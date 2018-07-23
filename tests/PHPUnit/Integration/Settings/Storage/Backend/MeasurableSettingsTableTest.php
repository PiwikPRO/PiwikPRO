<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration\Settings\Storage\Backend;

use Piwik\Config;
use Piwik\Db;
use Piwik\Settings\Storage\Backend\MeasurableSettingsTable;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group Settings
 * @group Backend
 * @group Storage
 */
class MeasurableSettingsTableTest extends IntegrationTestCase
{

    /**
     * @var MeasurableSettingsTable
     */
    private $backendSite1;

    /**
     * @var MeasurableSettingsTable
     */
    private $backendSite2;

    /**
     * @var MeasurableSettingsTable
     */
    private $backendSite1Plugin2;

    /**
     * @var MeasurableSettingsTable[]
     */
    private $allBackends = array();

    public function setUp()
    {
        parent::setUp();

        $this->backendSite1 = $this->createSettings(1, 'MyPluginName');
        $this->backendSite2 = $this->createSettings(2, 'MyPluginName');
        $this->backendSite1Plugin2 = $this->createSettings(1, 'MyPluginName2');
        $this->allBackends = array($this->backendSite1, $this->backendSite2, $this->backendSite1Plugin2);
    }

    private function createSettings($idSite, $plugin)
    {
        return new MeasurableSettingsTable($idSite, $plugin);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage No plugin name given
     */
    public function test_construct_shouldThrowAnException_IfPluginNameIsEmpty()
    {
        $this->createSettings(1, '');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage No idSite given
     */
    public function test_construct_shouldThrowAnException_IfIdSiteIsEmpty()
    {
        $this->createSettings(0, 'MyPlugin');
    }

    public function test_load_shouldNotHaveAnySettingsByDefault()
    {
        $this->assertSame(array(), $this->backendSite1->load());
        $this->assertSame(array(), $this->backendSite2->load());
        $this->assertSame(array(), $this->backendSite1Plugin2->load());
    }

    public function test_getStorageId_shouldIncludePluginNameAndLogin()
    {
        $this->assertSame('MeasurableSettings_1_MyPluginName', $this->backendSite1->getStorageId());
        $this->assertSame('MeasurableSettings_2_MyPluginName', $this->backendSite2->getStorageId());
        $this->assertSame('MeasurableSettings_1_MyPluginName2', $this->backendSite1Plugin2->getStorageId());
    }

    public function test_save_ShouldOnlySaveForSpecificPluginAndIdSite()
    {
        $value1 = array('Mysetting1' => 'value1', 'Mysetting2' => 'val');

        $this->backendSite1->save($value1);

        $this->assertSame($value1, $this->backendSite1->load());
        $this->assertSame(array(), $this->backendSite2->load());
        $this->assertSame(array(), $this->backendSite1Plugin2->load());

        $value2 = $this->getExampleValues();
        $this->backendSite2->save($value2);

        $this->assertSame($value1, $this->backendSite1->load());
        $this->assertSame($value2, $this->backendSite2->load());
        $this->assertSame(array(), $this->backendSite1Plugin2->load());

        $value3 = $this->getExampleValues();
        $this->backendSite1Plugin2->save($value3);

        $this->assertSame($value1, $this->backendSite1->load());
        $this->assertSame($value2, $this->backendSite2->load());
        $this->assertSame($value3, $this->backendSite1Plugin2->load());
    }

    public function test_delete_shouldDeleteAllValuesButOnlyForSpecificPluginAndIdSite()
    {
        $values = array();
        foreach ($this->allBackends as $index => $backend) {
            $values[$index] = $this->getExampleValues();
            $backend->save($values[$index]);
            $this->assertSame($values[$index], $backend->load());
        }

        foreach ($this->allBackends as $index => $backend) {
            $backend->delete();
            $values[$index] = array();

            // we make sure the values for all others are still set and only the current one was deleted
            foreach ($this->allBackends as $j => $backend2) {
                $this->assertSame($values[$j], $backend2->load());
            }
        }
    }

    public function test_save_DuplicateValuesShouldBeOverwritten()
    {
        $value1 = array('Mysetting1' => 'value1', 'Mysetting2' => 'val');

        $this->backendSite1->save($value1);

        $this->assertSame($value1, $this->backendSite1->load());

        $value2 = array('mytest' => 'test2', 'Mysetting2' => 'val', 'Mysetting1' => 'valueNew');
        $this->backendSite1->save($value2);

        $this->assertEquals($value2, $this->backendSite1->load());
    }

    public function test_save_NoLongerExistingValues_shouldBeRemoved()
    {
        $value = $this->saveValueForAllBackends();

        // overwrite only user1
        $value2 = array('mytest' => 'test2', 'Mysetting1' => 'valueNew');
        $this->backendSite1Plugin2->save($value2);
        $this->assertEquals($value2, $this->backendSite1Plugin2->load());

        // make sure other backends remain unchanged
        foreach ($this->allBackends as $backend) {
            if ($backend !== $this->backendSite1Plugin2) {
                $this->assertSame($value, $backend->load());
            }
        }
    }

    public function test_save_load_ShouldBeAbleToSaveAndLoadArrayValues()
    {
        $value1 = array('Mysetting1' => 'value1', 'Mysetting2' => array('val', 'val7', 'val5'));

        $this->backendSite1Plugin2->save($value1);
        $this->assertEquals($value1, $this->backendSite1Plugin2->load());
    }

    public function test_save_load_ShouldBeAbleToSaveAndLoadArrayValues_OnlyOneKey()
    {
        $value1 = array('Mysetting1' => 'value1', 'Mysetting2' => array('val'));

        $this->backendSite1Plugin2->save($value1);


        $value1 = array('Mysetting1' => 'value1', 'Mysetting2' => 'val');
        // it doesn't return an array for Mysetting2 but it is supposed to be casted to array by storage in this case
        $this->assertEquals($value1, $this->backendSite1Plugin2->load());
    }

    public function test_save_ShouldBeAbleToSaveBoolValues()
    {
        $value1 = array('Mysetting1' => true, 'Mysetting2' => array('val', 'val7', false, true, 'val5'));

        $this->backendSite1Plugin2->save($value1);

        $value1 = array('Mysetting1' => '1', 'Mysetting2' => array('val', 'val7', '0', '1', 'val5'));
        $this->assertEquals($value1, $this->backendSite1Plugin2->load());
    }

    public function test_save_ShouldIgnoreNullValues()
    {
        $value1 = array('Mysetting1' => true, 'MySetting3' => null, 'Mysetting2' => array('val', null, true, 'val5'));

        $this->backendSite1Plugin2->save($value1);

        $value1 = array('Mysetting1' => '1', 'Mysetting2' => array('val', '1', 'val5'));
        $this->assertEquals($value1, $this->backendSite1Plugin2->load());
    }

    public function test_removeAllUserSettingsForUser_shouldOnlyRemoveSettingsForThatUser()
    {
        $value = $this->saveValueForAllBackends();

        MeasurableSettingsTable::removeAllSettingsForSite('2');

        foreach ($this->allBackends as $backend) {
            if ($backend === $this->backendSite2) {
                $this->assertSame(array(), $backend->load());
            } else {
                $this->assertSame($value, $backend->load());
            }
        }
    }

    public function test_removeAllSettingsForPlugin_shouldOnlyRemoveSettingsForThatPlugin()
    {
        $value = $this->saveValueForAllBackends();

        MeasurableSettingsTable::removeAllSettingsForPlugin('MyPluginName');

        foreach ($this->allBackends as $backend) {
            if ($backend === $this->backendSite1Plugin2) {
                $this->assertSame($value, $backend->load());
            } else {
                $this->assertSame(array(), $backend->load());
            }
        }
    }

    private function saveValueForAllBackends()
    {
        $value = array('Mysetting1' => 'value1', 'Mysetting2' => 'val');

        foreach ($this->allBackends as $backend) {
            $backend->save($value);
            $this->assertSame($value, $backend->load());
        }

        return $value;
    }

    private function getExampleValues()
    {
        return array('Mysetting3' => 'value3', 'Mysetting4' . rand(4,99) => 'val' . rand(0, 10));
    }
}
