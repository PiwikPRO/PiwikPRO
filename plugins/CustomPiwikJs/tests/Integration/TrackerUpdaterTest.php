<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link    http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CustomPiwikJs\tests\Integration;

use Piwik\Plugins\CustomPiwikJs\File;
use Piwik\Plugins\CustomPiwikJs\tests\Framework\Mock\PluginTrackerFilesMock;
use Piwik\Plugins\CustomPiwikJs\TrackerUpdater;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group CustomPiwikJs
 * @group PiwikJsManipulatorTest
 * @group PiwikJsManipulator
 * @group Plugins
 */
class TrackerUpdaterTest extends IntegrationTestCase
{
    private $dir;
    private $piwikJsChangedEventPath = null;

    public function setUp()
    {
        parent::setUp();
        $this->dir = PIWIK_DOCUMENT_ROOT . '/plugins/CustomPiwikJs/tests/resources/';
        $this->piwikJsChangedEventPath = null;

        $this->cleanUp();
    }

    public function tearDown()
    {
        parent::tearDown();

        $this->cleanUp();
    }

    private function cleanUp()
    {
        $target = $this->dir . 'MyTestTarget.js';
        if (file_exists($target)) {
            unlink($target);
        }

        $nonExistentFile = $this->dir . 'MyNotExisIngFilessss.js';
        if (file_exists($nonExistentFile)) {
            unlink($nonExistentFile);
        }
    }

    private function makeUpdater($from = null, $to = null)
    {
        return new TrackerUpdater($from, $to);
    }

    public function test_construct_setsDefaults()
    {
        $updater = $this->makeUpdater();
        $fromFile = $updater->getFromFile();
        $toFile = $updater->getToFile();
        $this->assertTrue($fromFile instanceof File);
        $this->assertTrue($toFile instanceof File);

        $this->assertSame(basename(TrackerUpdater::ORIGINAL_PIWIK_JS), $fromFile->getName());
        $this->assertSame(basename(TrackerUpdater::TARGET_PIWIK_JS), $toFile->getName());
    }

    public function test_setFormFile_getFromFile()
    {
        $updater = $this->makeUpdater();
        $testFile = new File('foobar');
        $updater->setFromFile($testFile);

        $this->assertSame($testFile, $updater->getFromFile());
    }

    public function test_setFormFile_CanBeString()
    {
        $updater = $this->makeUpdater();
        $updater->setFromFile('foobar');

        $this->assertSame('foobar', $updater->getFromFile()->getName());
    }

    public function test_setToFile_getToFile()
    {
        $updater = $this->makeUpdater();
        $testFile = new File('foobar');
        $updater->setToFile($testFile);

        $this->assertSame($testFile, $updater->getToFile());
    }

    public function test_setToFile_CanBeString()
    {
        $updater = $this->makeUpdater();
        $updater->setToFile('foobar');

        $this->assertSame('foobar', $updater->getToFile()->getName());
    }

    public function test_checkWillSucceed_shouldNotThrowExceptionIfPiwikJsTargetIsWritable()
    {
        $updater = $this->makeUpdater();
        $updater->checkWillSucceed();

        $this->assertTrue(true);
    }

    /**
     * @expectedException \Piwik\Plugins\CustomPiwikJs\Exception\AccessDeniedException
     * @expectedExceptionMessage not writable
     */
    public function test_checkWillSucceed_shouldNotThrowExceptionIfTargetIsNotWritable()
    {
        $updater = $this->makeUpdater(null, $this->dir . 'not-writable/MyNotExisIngFilessss.js');
        $updater->checkWillSucceed();
    }

    public function test_checkWillSucceed_shouldNotThrowExceptionIfTargetIsWritable()
    {
        $updater = $this->makeUpdater(null, $this->dir . 'MyNotExisIngFilessss.js');
        $updater->checkWillSucceed();
    }

    public function test_getCurrentTrackerFileContent()
    {
        $targetFile = $this->dir . 'testpiwik.js';

        $updater = $this->makeUpdater(null, $targetFile);
        $content = $updater->getCurrentTrackerFileContent();

        $this->assertSame(file_get_contents($targetFile), $content);
    }

    public function test_getUpdatedTrackerFileContent_returnsGeneratedPiwikJsWithMergedTrackerFiles_WhenTheyExist()
    {
        $source = $this->dir . 'testpiwik.js';
        $target = $this->dir . 'MyTestTarget.js';

        $updater = $this->makeUpdater($source, $target);
        $updater->setTrackerFiles(new PluginTrackerFilesMock(array(
            $this->dir . 'tracker.js', $this->dir . 'tracker.min.js'
        )));
        $content = $updater->getUpdatedTrackerFileContent();

        $this->assertSame('/** MyHeader*/
var PiwikJs = "mytest";

/*!!! pluginTrackerHook */

/* GENERATED: tracker.min.js */

/* END GENERATED: tracker.min.js */


/* GENERATED: tracker.js */

/* END GENERATED: tracker.js */


var myArray = [];
', $content);
    }

    public function test_getUpdatedTrackerFileContent_returnsSourceFile_IfNoTrackerFilesFound()
    {
        $source = $this->dir . 'testpiwik.js';
        $target = $this->dir . 'MyTestTarget.js';

        $updater = $this->makeUpdater($source, $target);
        $updater->setTrackerFiles(new PluginTrackerFilesMock(array()));
        $content = $updater->getUpdatedTrackerFileContent();

        $this->assertSame(file_get_contents($source), $content);
    }

    public function test_update_shouldNotThrowAnError_IfTargetFileIsNotWritable()
    {
        $updater = $this->makeUpdater(null, $this->dir . 'not-writable/MyNotExisIngFilessss.js');
        $updater->update();
        $this->assertTrue(true);
        $this->assertNull($this->piwikJsChangedEventPath);
    }

    public function test_update_shouldNotWriteToFileIfThereIsNothingToChange()
    {
        $source = $this->dir . 'testpiwik.js';
        $target = $this->dir . 'MyTestTarget.js';
        file_put_contents($target, file_get_contents($source));
        $updater = $this->makeUpdater($this->dir . 'testpiwik.js', $target);
        $updater->setTrackerFiles(new PluginTrackerFilesMock(array()));
        // mock that does not find any files . therefore there is nothing to di
        $updater->update();

        $this->assertSame(file_get_contents($source), file_get_contents($target));
        $this->assertNull($this->piwikJsChangedEventPath);
    }

    public function test_update_targetFileIfPluginsDefineDifferentFiles()
    {
        $target = $this->dir . 'MyTestTarget.js';
        file_put_contents($target, ''); // file has to exist in order to work

        $updater = $this->makeUpdater($this->dir . 'testpiwik.js', $target);
        $updater->setTrackerFiles(new PluginTrackerFilesMock(array(
            $this->dir . 'tracker.js', $this->dir . 'tracker.min.js'
        )));
        $updater->update();

        $this->assertSame('/** MyHeader*/
var PiwikJs = "mytest";

/*!!! pluginTrackerHook */

/* GENERATED: tracker.min.js */

/* END GENERATED: tracker.min.js */


/* GENERATED: tracker.js */

/* END GENERATED: tracker.js */


var myArray = [];
', file_get_contents($target));
        $this->assertEquals($target, $this->piwikJsChangedEventPath);
    }

    public function provideContainerConfig()
    {
        return [
            'observers.global' => \DI\add([
                ['CustomPiwikJs.piwikJsChanged', function ($path) {
                    $this->piwikJsChangedEventPath = $path;
                }],
            ]),
        ];
    }
}
