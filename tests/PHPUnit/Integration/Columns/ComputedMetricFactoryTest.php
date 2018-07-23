<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration\Columns;

use Piwik\Columns\ComputedMetricFactory;
use Piwik\Columns\MetricsList;
use Piwik\Plugin\ComputedMetric;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Translate;

/**
 * @group Core
 */
class ComputedMetricFactoryTest extends IntegrationTestCase
{
    /**
     * @var ComputedMetricFactory
     */
    private $factory;

    public function setUp()
    {
        parent::setUp();

        Translate::loadEnglishTranslation();

        $this->factory = new ComputedMetricFactory(MetricsList::get());
    }

    public function tearDown()
    {
        Translate::unloadEnglishTranslation();
        parent::tearDown();
    }

    public function test_createComputedMetric_createAvgMetric()
    {
        $metric = $this->factory->createComputedMetric('bounce_count', 'nb_visits', ComputedMetric::AGGREGATION_AVG);

        $this->assertSame('avg_bounce_count_per_visits', $metric->getName());
        $this->assertSame('Avg. Actions In Visit per Visit', $metric->getTranslatedName());
        $this->assertSame('Average value of "Actions In Visit" per "Visits".', $metric->getDocumentation());
        $this->assertSame('General_Visitors', $metric->getCategoryId());
    }

    public function test_createComputedMetric_createRateMetric()
    {
        $metric = $this->factory->createComputedMetric('bounce_count', 'nb_visits', ComputedMetric::AGGREGATION_RATE);

        $this->assertSame('bounce_count_visits_rate', $metric->getName());
        $this->assertSame('Bounces Rate', $metric->getTranslatedName());
        $this->assertSame('The ratio of "Actions In Visit" out of all "Visits".', $metric->getDocumentation());
        $this->assertSame('General_Visitors', $metric->getCategoryId());
    }

}