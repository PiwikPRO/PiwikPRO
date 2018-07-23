<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Tests\Unit;

use Piwik\Console;
use Piwik\Version;

/**
 * @group Console
 */
class ConsoleTest extends \PHPUnit_Framework_TestCase
{
    public function testIsApplicationNameAndVersionCorrect()
    {
        $console = new Console();

        $this->assertEquals('Piwik', $console->getName());
        $this->assertEquals(Version::VERSION, $console->getVersion());
    }
}
