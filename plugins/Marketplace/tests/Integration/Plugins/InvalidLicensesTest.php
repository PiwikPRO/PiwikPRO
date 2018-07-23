<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Marketplace\tests\Integration\Plugins;

use Piwik\Cache\Backend\ArrayCache;
use Piwik\Cache\Eager;
use Piwik\Container\StaticContainer;
use Piwik\Plugins\Marketplace\Consumer;
use Piwik\Plugins\Marketplace\Plugins;
use Piwik\Plugins\Marketplace\Plugins\InvalidLicenses;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Plugins\Marketplace\tests\Framework\Mock\Consumer as ConsumerBuilder;
use Piwik\Translate;

class CustomInvalidLicenses extends InvalidLicenses {
    private $isActivated = true;

    public function setPluginIsActivated($isActivated)
    {
        $this->isActivated = $isActivated;
    }

    public function isPluginActivated($pluginName)
    {
        return $this->isActivated;
    }
}

/**
 * @group Marketplace
 * @group InvalidLicensesTest
 * @group InvalidLicenses
 * @group Plugins
 */
class InvalidLicensesTest extends IntegrationTestCase
{
    /**
     * @var Eager
     */
    private $cache;

    private $cacheKey = 'Marketplace_ExpiredPlugins';

    public function setUp()
    {
        parent::setUp();

        Translate::loadEnglishTranslation();

        $this->cache = new Eager(new ArrayCache(), 'test');
    }

    public function tearDown()
    {
        Translate::unloadEnglishTranslation();
        parent::tearDown();
    }

    public function test_getNamesOfExpiredPaidPlugins_validLicenses_noPaidPluginActivated()
    {
        $expired = $this->buildWithValidLicense();
        $expired->setPluginIsActivated(false);

        $expected = array('exceeded' => array(), 'expired' => array(), 'noLicense' => array());

        $this->assertSame($expected, $expired->getPluginNamesOfInvalidLicenses());
    }

    public function test_getNamesOfExpiredPaidPlugins_noLicenses_noPaidPluginActivated()
    {
        $expired = $this->buildWithNoLicense();
        $expired->setPluginIsActivated(false);

        $expected = array(
            'exceeded' => array(),
            'expired' => array(),
            'noLicense' => array());

        $this->assertSame($expected, $expired->getPluginNamesOfInvalidLicenses());
    }

    public function test_getNamesOfExpiredPaidPlugins_invalidLicenses_noPaidPluginActivated()
    {
        $expired = $this->buildWithExpiredLicense();
        $expired->setPluginIsActivated(false);

        $expected = array(
            'exceeded' => array(),
            'expired' => array(),
            'noLicense' => array());

        $this->assertSame($expected, $expired->getPluginNamesOfInvalidLicenses());
    }

    public function test_getNamesOfExpiredPaidPlugins_exceededLicenses_noPaidPluginActivated()
    {
        $expired = $this->buildWithExceededLicense();
        $expired->setPluginIsActivated(false);

        $expected = array('exceeded' => array(), 'expired' => array(), 'noLicense' => array());

        $this->assertSame($expected, $expired->getPluginNamesOfInvalidLicenses());
    }

    public function test_getNamesOfExpiredPaidPlugins_validLicenses()
    {
        $expired = $this->buildWithValidLicense();

        $expected = array('exceeded' => array(), 'expired' => array(), 'noLicense' => array());

        $this->assertSame($expected, $expired->getPluginNamesOfInvalidLicenses());
    }

    public function test_getNamesOfExpiredPaidPlugins_noLicenses()
    {
        $expired = $this->buildWithNoLicense();

        $expected = array(
            'exceeded' => array(),
            'expired' => array(),
            'noLicense' => array('PaidPlugin1'));

        $this->assertSame($expected, $expired->getPluginNamesOfInvalidLicenses());
    }

    public function test_getNamesOfExpiredPaidPlugins_invalidLicenses()
    {
        $expired = $this->buildWithExpiredLicense();

        $expected = array(
            'exceeded' => array(),
            'expired' => array('PaidPlugin1'),
            'noLicense' => array());

        $this->assertSame($expected, $expired->getPluginNamesOfInvalidLicenses());
    }

    public function test_getNamesOfExpiredPaidPlugins_exceededLicenses()
    {
        $expired = $this->buildWithExceededLicense();

        $expected = array(
            'exceeded' => array('PaidPlugin2'),
            'expired' => array('PaidPlugin1'),
            'noLicense' => array());
        $this->assertEquals($expected, $expired->getPluginNamesOfInvalidLicenses());
    }

    public function test_getNamesOfExpiredPaidPlugins_shouldCacheAnyResult()
    {
        $this->assertFalse($this->cache->contains($this->cacheKey));

        $this->buildWithValidLicense()->getPluginNamesOfInvalidLicenses();

        $this->assertTrue($this->cache->contains($this->cacheKey));

        $expected = array('exceeded' => array(), 'expired' => array(), 'noLicense' => array());

        $this->assertSame($expected, $this->cache->fetch($this->cacheKey));
    }

    public function test_getNamesOfExpiredPaidPlugins_shouldCache_IfNotValidLicenseKeyButPaidPluginsInstalled()
    {
        $this->buildWithExpiredLicense()->getPluginNamesOfInvalidLicenses();

        $expected = array(
            'exceeded' => array(),
            'expired' => array('PaidPlugin1'),
            'noLicense' => array());

        $this->assertSame($expected, $this->cache->fetch($this->cacheKey));
    }

    public function test_getMessageExceededLicenses_getMessageExpiredLicenses_validLicenses_noPaidPluginActivated()
    {
        $expired = $this->buildWithValidLicense();
        $expired->setPluginIsActivated(false);

        $this->assertNull($expired->getMessageExceededLicenses());
        $this->assertNull($expired->getMessageExpiredLicenses());
        $this->assertNull($expired->getMessageNoLicense());
    }

    public function test_getMessageExceededLicenses_getMessageExpiredLicenses_invalidLicenses_noPaidPluginActivated()
    {
        $expired = $this->buildWithExpiredLicense();
        $expired->setPluginIsActivated(false);

        $this->assertNull($expired->getMessageExceededLicenses());
        $this->assertNull($expired->getMessageExpiredLicenses());
        $this->assertNull($expired->getMessageNoLicense());
    }

    public function test_getMessageExceededLicenses_getMessageExpiredLicenses_exceededLicenses_noPaidPluginActivated()
    {
        $expired = $this->buildWithExceededLicense();
        $expired->setPluginIsActivated(false);
        $this->assertNull($expired->getMessageExceededLicenses());
        $this->assertNull($expired->getMessageExpiredLicenses());
        $this->assertNull($expired->getMessageNoLicense());
    }

    public function test_getMessageExceededLicenses_getMessageExpiredLicenses_validLicenses_PaidPluginActivated()
    {
        $expired = $this->buildWithValidLicense();

        $this->assertNull($expired->getMessageExceededLicenses());
        $this->assertNull($expired->getMessageExpiredLicenses());
        $this->assertNull($expired->getMessageNoLicense());
    }

    public function test_getMessageExceededLicenses_getMessageExpiredLicenses_noLicenses_PaidPluginActivated()
    {
        // in theory we would need to show a warning as there is no license, but this can also happen if there's some random
        // error and the user actually has a license, eg if the request aborted when fetching consumer etc
        $expired = $this->buildWithNoLicense();

        $this->assertEquals('', $expired->getMessageExceededLicenses());
        $this->assertEquals('', $expired->getMessageExpiredLicenses());
        $this->assertEquals('You are using the following plugins without a license: <strong>PaidPlugin1</strong>. <br/>To resolve this issue either update your license key, <strong><a href="https://shop.piwik.org/my-account" target="_blank" rel="noreferrer">get a subscription now</a></strong> or deactivate the plugin. <br/><a href="?module=Marketplace&action=subscriptionOverview">View your plugin subscriptions.</a>', $expired->getMessageNoLicense());
    }

    public function test_getMessageExceededLicenses_getMessageExpiredLicenses_invalidLicenses_PaidPluginActivated()
    {
        $expired = $this->buildWithExpiredLicense();

        $this->assertNull($expired->getMessageExceededLicenses());
        $this->assertEquals('The licenses for the following plugins are expired: <strong>PaidPlugin1</strong>. <br/>You will no longer receive any updates for these plugins. To resolve this issue either <strong><a href="https://shop.piwik.org/my-account" target="_blank" rel="noreferrer">renew your subscription now</a></strong>, or deactivate the plugin if you no longer use it. <br/><a href="?module=Marketplace&action=subscriptionOverview">View your plugin subscriptions.</a>', $expired->getMessageExpiredLicenses());
    }

    public function test_getMessageExceededLicenses_getMessageExpiredLicenses_exceededLicenses_PaidPluginActivated()
    {
        $expired = $this->buildWithExceededLicense();
        $this->assertEquals('The licenses for the following plugins are no longer valid as the number of authorized users for the license is exceeded: <strong>PaidPlugin2</strong>. <br/>You will not be able to download updates for these plugins. To resolve this issue either delete some users or <strong><a href="https://shop.piwik.org/my-account" target="_blank" rel="noreferrer">upgrade the subscription now</a></strong>. <br/><a href="?module=Marketplace&action=subscriptionOverview">View your plugin subscriptions.</a>', $expired->getMessageExceededLicenses());
        $this->assertEquals('The licenses for the following plugins are expired: <strong>PaidPlugin1</strong>. <br/>You will no longer receive any updates for these plugins. To resolve this issue either <strong><a href="https://shop.piwik.org/my-account" target="_blank" rel="noreferrer">renew your subscription now</a></strong>, or deactivate the plugin if you no longer use it. <br/><a href="?module=Marketplace&action=subscriptionOverview">View your plugin subscriptions.</a>', $expired->getMessageExpiredLicenses());
    }

    public function test_getMessageMissingLicenses_getMessageMissingLicenses_PaidPluginActivated()
    {
        $expired = $this->buildWithNoLicense();
        $this->assertEquals('You are using the following plugins without a license: <strong>PaidPlugin1</strong>. <br/>To resolve this issue either update your license key, <strong><a href="https://shop.piwik.org/my-account" target="_blank" rel="noreferrer">get a subscription now</a></strong> or deactivate the plugin. <br/><a href="?module=Marketplace&action=subscriptionOverview">View your plugin subscriptions.</a>', $expired->getMessageNoLicense());
    }

    private function buildWithValidLicense()
    {
        $consumer = ConsumerBuilder::buildValidLicense();
        return $this->buildInvalidLicense($consumer);
    }

    private function buildWithExpiredLicense()
    {
        $consumer = ConsumerBuilder::buildExpiredLicense();
        return $this->buildInvalidLicense($consumer);
    }

    private function buildWithNoLicense()
    {
        $consumer = ConsumerBuilder::buildNoLicense();
        return $this->buildInvalidLicense($consumer);
    }

    private function buildWithExceededLicense()
    {
        $consumer = ConsumerBuilder::buildExceededLicense();
        return $this->buildInvalidLicense($consumer);
    }

    /**
     * @param Consumer $consumer
     * @return CustomInvalidLicenses
     */
    private function buildInvalidLicense($consumer)
    {
        $translator = StaticContainer::get('Piwik\Translation\Translator');
        $advertising = StaticContainer::get('Piwik\ProfessionalServices\Advertising');
        $client = $consumer->getApiClient();
        $plugins = new Plugins($client, $consumer, $advertising);

        $licenses = new CustomInvalidLicenses($client, $this->cache, $translator, $plugins);
        $licenses->clearCache();
        return $licenses;
    }

}
