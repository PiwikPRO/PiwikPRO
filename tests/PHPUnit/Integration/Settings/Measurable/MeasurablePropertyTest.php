<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration\Settings\Plugin;

use Piwik\Config;
use Piwik\Db;
use Piwik\Settings\FieldConfig;
use Piwik\Settings\Measurable\MeasurableProperty;
use Piwik\Settings\Measurable\MeasurableSetting;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\Settings\FakeMeasurableSettings;
use Piwik\Tests\Integration\Settings\IntegrationTestCase;

/**
 * @group MeasurableSettings
 * @group Settings
 * @group MeasurableProperty
 */
class MeasurablePropertyTest extends IntegrationTestCase
{

    public function setUp()
    {
        parent::setUp();
        Db::destroyDatabaseObject();
    }

    protected function createSettingsInstance()
    {
        if (!Fixture::siteCreated(1)) {
            Fixture::createWebsite('2014-01-01 01:01:01');
        }

        return new FakeMeasurableSettings($idSite = 1);
    }

    public function test_constructor_shouldNotEstablishADatabaseConnection()
    {
        $this->assertNotDbConnectionCreated();

        new MeasurableProperty('ecommerce', $default = 5, FieldConfig::TYPE_INT, 'MyPlugin', $idSite = 1);

        $this->assertNotDbConnectionCreated();
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Name "name" is not allowed to be used
     */
    public function test_constructor_shouldThrowAnExceptionWhenNotWhitelistedNameIsUsed()
    {
        new MeasurableProperty('name', $default = 5, FieldConfig::TYPE_INT, 'MyPlugin', $idSite = 1);
    }


}
