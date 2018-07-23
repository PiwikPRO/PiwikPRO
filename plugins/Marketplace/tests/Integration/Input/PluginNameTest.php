<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Marketplace\tests\Integration\Input;

use Piwik\Plugins\Marketplace\Input\PluginName;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group Plugins
 * @group Marketplace
 * @group PluginNameTest
 * @group PluginName
 */
class PluginNameTest extends IntegrationTestCase
{
    public function tearDown()
    {
        unset($_GET['pluginName']);
    }

    public function test_findsPluginName()
    {
        $this->setPluginName('CoreFooBar');

        $pluginName = new PluginName();
        $this->assertSame('CoreFooBar', $pluginName->getPluginName());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Invalid plugin name given
     */
    public function test_throws_exception_ifInvalidName()
    {
        $this->setPluginName('CoreFooBar-?4');

        $pluginName = new PluginName();
        $pluginName->getPluginName();
    }

    private function setPluginName($name)
    {
        $_GET['pluginName'] = $name;
    }


}
