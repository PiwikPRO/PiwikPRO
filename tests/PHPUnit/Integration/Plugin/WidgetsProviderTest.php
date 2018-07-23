<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration\Plugin;

use Piwik\Container\StaticContainer;
use Piwik\Db;
use Piwik\Plugin\WidgetsProvider;
use Piwik\Settings\Storage;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Widget\WidgetConfig;
use Piwik\Widget\WidgetContainerConfig;

/**
 * @group WidgetsProvider
 * @group WidgetsProviderTest
 */
class WidgetsProviderTest extends IntegrationTestCase
{
    /**
     * @var WidgetsProvider
     */
    private $widgets;

    public function setUp()
    {
        parent::setUp();

        $_GET['idSite'] = 1;
        if (!Fixture::siteCreated(1)) {
            Fixture::createWebsite('2015-01-01 00:00:00');
        }

        $this->widgets = new WidgetsProvider(StaticContainer::get('Piwik\Plugin\Manager'));
    }

    public function tearDown()
    {
        parent::tearDown();
        unset($_GET['idSite']);
    }

    public function test_getWidgetContainerConfigs_shouldOnlyFindWidgetContainerConfigs()
    {
        $configs = $this->widgets->getWidgetContainerConfigs();

        $this->assertGreaterThanOrEqual(3, count($configs));

        foreach ($configs as $config) {
            $this->assertTrue($config instanceof WidgetContainerConfig);
        }
    }

    public function test_getWidgetConfigs_shouldFindWidgetConfigs()
    {
        $configs = $this->widgets->getWidgetConfigs();

        $this->assertGreaterThanOrEqual(10, count($configs));

        foreach ($configs as $config) {
            $this->assertTrue($config instanceof WidgetConfig);
            $this->assertFalse($config instanceof WidgetContainerConfig);
        }
    }

    public function test_getWidgetConfigs_shouldSetModuleAndActionForEachConfig()
    {
        $configs = $this->widgets->getWidgetConfigs();

        foreach ($configs as $config) {
            $this->assertNotEmpty($config->getModule());
            $this->assertNotEmpty($config->getAction());
        }
    }
}
