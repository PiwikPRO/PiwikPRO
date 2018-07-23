<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Referrers\tests;

use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Period;

require_once PIWIK_INCLUDE_PATH . '/plugins/Referrers/Referrers.php';

/**
 * @group Plugin
 */
class ReferrersTest extends \PHPUnit_Framework_TestCase
{
    public function removeUrlProtocolTestData()
    {
        return array(
            array('http://www.facebook.com', 'www.facebook.com'),
            array('https://bla.fr', 'bla.fr'),
            array('ftp://bla.fr', 'bla.fr'),
            array('udp://bla.fr', 'bla.fr'),
            array('bla.fr', 'bla.fr'),
            array('ASDasdASDDasd', 'ASDasdASDDasd'),
        );
    }

    /**
     * @group Plugins
     *
     * @dataProvider removeUrlProtocolTestData
     */
    public function testRemoveUrlProtocol($url, $expected)
    {
        $this->assertEquals($expected, \Piwik\Plugins\Referrers\removeUrlProtocol($url));
    }
}
