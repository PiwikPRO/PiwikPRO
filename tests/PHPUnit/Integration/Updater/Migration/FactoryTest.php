<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration\Updater\Migration;

use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Updater\Migration;
use Piwik\Updater\Migration\Db\Factory as DbFactory;
use Piwik\Updater\Migration\Plugin\Factory as PluginFactory;

/**
 * @group Core
 * @group Updater
 * @group Migration
 */
class FactoryTest extends IntegrationTestCase
{
    /**
     * @var Migration\Factory
     */
    private $factory;

    public function setUp()
    {
        parent::setUp();

        $this->factory = new Migration\Factory(new DbFactory(), new PluginFactory());
    }

    public function test_db_holdsDatabaseFactory()
    {
        $this->assertTrue($this->factory->db instanceof DbFactory);
    }

    public function test_plugin_holdsPluginFactory()
    {
        $this->assertTrue($this->factory->plugin instanceof PluginFactory);
    }

}
