<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\SegmentEditor\tests\Unit;

use Piwik\Plugins\SegmentEditor\SegmentQueryDecorator;
use Piwik\Plugins\SegmentEditor\Services\StoredSegmentService;
use Piwik\Segment\SegmentExpression;
use Piwik\Tests\Framework\Mock\Plugin\LogTablesProvider;

/**
 * @group SegmentEditor
 * @group SegmentEditor_Unit
 */
class SegmentQueryDecoratorTest extends \PHPUnit_Framework_TestCase
{
    public static $storedSegments = array(
        array('definition' => 'countryCode==abc', 'idsegment' => 1),
        array('definition' => 'region!=FL', 'idsegment' => 2),
        array('definition' => 'browserCode==def;visitCount>2', 'idsegment' => 3),
        array('definition' => 'region!=FL', 'idsegment' => 4),
    );

    /**
     * @var SegmentQueryDecorator
     */
    private $decorator;

    public function setUp()
    {
        parent::setUp();

        /** @var StoredSegmentService $service */
        $service = $this->getMockSegmentEditorService();
        $logTables = new LogTablesProvider();
        $this->decorator = new SegmentQueryDecorator($service, $logTables);
    }

    public function test_getSelectQueryString_DoesNotDecorateSql_WhenNoSegmentUsed()
    {
        $expression = new SegmentExpression('');
        $expression->parseSubExpressions();

        $query = $this->decorator->getSelectQueryString($expression, '*', 'log_visit', '', array(), '', '', '');

        $this->assertStringStartsNotWith('/* idSegments', $query['sql']);
    }

    public function test_getSelectQueryString_DoesNotDecorateSql_WhenNoSegmentMatchesUsedSegment()
    {
        $expression = new SegmentExpression('referrerName==ooga');
        $expression->parseSubExpressions();

        $query = $this->decorator->getSelectQueryString($expression, '*', 'log_visit', '', array(), '', '', '');

        $this->assertStringStartsNotWith('/* idSegments', $query['sql']);
    }

    public function test_getSelectQueryString_DecoratesSql_WhenOneSegmentMatchesUsedSegment()
    {
        $expression = new SegmentExpression('browserCode==def;visitCount>2');
        $expression->parseSubExpressions();

        $query = $this->decorator->getSelectQueryString($expression, '*', 'log_visit', '', array(), '', '', '');

        $this->assertStringStartsWith('/* idSegments = [3] */', $query['sql']);
    }

    public function test_getSelectQueryString_DecoratesSql_WhenMultipleStoredSegmentsMatchUsedSegment()
    {
        $expression = new SegmentExpression('region!=FL');
        $expression->parseSubExpressions();

        $query = $this->decorator->getSelectQueryString($expression, '*', 'log_visit', '', array(), '', '', '');

        $this->assertStringStartsWith('/* idSegments = [2, 4] */', $query['sql']);
    }

    private function getMockSegmentEditorService()
    {
        $mock = $this->getMock('Piwik\Plugins\SegmentEditor\Services\StoredSegmentService',
            array('getAllSegmentsAndIgnoreVisibility'), array(), '', $callOriginalConstructor = false);
        $mock->expects($this->any())->method('getAllSegmentsAndIgnoreVisibility')->willReturn(self::$storedSegments);
        return $mock;
    }

}
