<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Marketplace\tests\Integration;

use Piwik\Plugins\Marketplace\LicenseKey;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group Plugins
 * @group Marketplace
 * @group LicenseKeyTest
 * @group LicenseKey
 */
class LicenseKeyTest extends IntegrationTestCase
{
    /**
     * @var LicenseKey
     */
    private $licenseKey;

    public function setUp()
    {
        parent::setUp();

        $this->licenseKey = $this->buildLicenseKey();
    }

    public function test_get_noLicenseKeyIsSetByDefault()
    {
        $this->assertFalse($this->licenseKey->get());
        $this->assertFalse($this->licenseKey->has());
    }

    public function test_set_get_persistsALicenseKey()
    {
        $key = 'foobarBaz';
        $this->licenseKey->set($key);
        $this->assertSame($key, $this->licenseKey->get());

        // verify it is saved across requests by creating a new instance
        $this->assertPersistedLicenseKeyEquals($key);
    }

    public function test_set_shouldOverwriteAnExistingKey()
    {
        $this->setExampleLicenseKey();

        $key = 'foobarBaz2Unique299';
        $this->assertPersistedLicenseKeyNotEquals($key);
        $this->licenseKey->set($key);
        $this->assertPersistedLicenseKeyEquals($key);
    }

    public function test_set_deletesAnExistingLicenseKey_IfValueIsFalse()
    {
        $this->setExampleLicenseKey();

        $this->licenseKey->set(false);
        $this->assertFalse($this->licenseKey->has());
    }

    public function test_set_deletesAnExistingLicenseKey_IfValueIsNotSet()
    {
        $this->setExampleLicenseKey();

        $this->licenseKey->set(null);
        $this->assertFalse($this->licenseKey->has());
    }

    public function test_has_detectsWhetherANonEmptyKeyIsSet()
    {
        $this->assertNotHasPersistedLicenseKey();
        $this->setExampleLicenseKey();
        $this->assertHasPersistedLicenseKey();
        $this->licenseKey->set('');
        $this->assertNotHasPersistedLicenseKey();
        $this->licenseKey->set('1');
        $this->assertHasPersistedLicenseKey();
        $this->licenseKey->set('0');
        $this->assertHasPersistedLicenseKey();
        $this->licenseKey->set(null);
        $this->assertNotHasPersistedLicenseKey();
    }

    private function assertHasPersistedLicenseKey()
    {
        // we create a new instance so it's actually persisted and not hold in an object instance
        $this->assertTrue($this->buildLicenseKey()->has());
    }

    private function assertNotHasPersistedLicenseKey()
    {
        // we create a new instance so it's actually persisted and not hold in an object instance
        $this->assertFalse($this->buildLicenseKey()->has());
    }

    private function assertPersistedLicenseKeyEquals($expectedKey)
    {
        // we create a new instance so it's actually persisted and not hold in an object instance
        $this->assertSame($expectedKey, $this->buildLicenseKey()->get());
    }

    private function assertPersistedLicenseKeyNotEquals($expectedKey)
    {
        // we create a new instance so it's actually persisted and not hold in an object instance
        $this->assertNotSame($expectedKey, $this->buildLicenseKey()->get());
    }

    private function setExampleLicenseKey()
    {
        $this->licenseKey->set('foo');
        $this->assertTrue($this->licenseKey->has());
    }

    private function buildLicenseKey()
    {
        return new LicenseKey();
    }

}
