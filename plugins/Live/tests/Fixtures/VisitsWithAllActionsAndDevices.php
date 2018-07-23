<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link    http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Plugins\Live\tests\Fixtures;

use Piwik\Date;
use Piwik\Tests\Framework\Fixture;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\CustomDimensions\API as CustomDimensionsApi;

/**
 * Generates many visits for the same visitor
 */
class VisitsWithAllActionsAndDevices extends Fixture
{
    public $dateTime = '2010-02-01 11:22:33';
    public $idSite = 1;

    public function setUp()
    {
        if (!self::siteCreated($idSite = 1)) {
            self::createWebsite($this->dateTime, 1);
        }

        GoalsApi::getInstance()->addGoal(1, 'Successfully used Search', 'manually', '', 'contains');
        
        CustomDimensionsApi::getInstance()->configureNewCustomDimension(1, 'age', 'visit', 1);
        CustomDimensionsApi::getInstance()->configureNewCustomDimension(1, 'currency', 'action', 1);

        $t = self::getTracker($this->idSite, $this->dateTime, $defaultInit = true);
        $t->setTokenAuth(self::getTokenAuth());

        $t->setUserId('X4F66G776HGI');

        // smart display
        $this->trackDeviceVisit($t, Date::factory($this->dateTime)->addHour(0)->getDatetime(), 'Mozilla/5.0 (Linux; U; Android 4.0.4; de-de; VSD220 Build/IMM76D.UI23ED12_VSC) AppleWebKit/534.30 (KHTML, like Gecko) Version/4.0 Safari/534.30');

        // media player
        $this->trackVisitMediaPlayer($t, Date::factory($this->dateTime)->addHour(6.33)->getDatetime());

        // tv
        $this->trackDeviceVisit($t, Date::factory($this->dateTime)->addHour(16.6)->getDatetime(), 'Mozilla/5.0 (Linux; Android 4.2.2; AFTB Build/JDQ39) AppleWebKit/537.22 (KHTML, like Gecko) Chrome/25.0.1364.173 Mobile Safari/537.22 cordova-amazon-fireos/3.4.0 AmazonWebAppPlatform/3.4.0;2.0');

        // desktop
        $this->trackDeviceVisit($t, Date::factory($this->dateTime)->addHour(26.7)->getDatetime(), 'Mozilla/5.0 (Windows NT 6.3; Win64; x64; Trident/7.0; NP06; rv:11.0) like Gecko');
        $this->trackDeviceVisit($t, Date::factory($this->dateTime)->addHour(32.5)->getDatetime(), 'Safari/9537.73.11 CFNetwork/673.0.3 Darwin/13.0.0 (x86_64) (MacBookAir6%2C2)');

        // car browser
        $this->trackDeviceVisit($t, Date::factory($this->dateTime)->addHour(60.4)->getDatetime(), 'Mozilla/5.0 (X11; u; Linux; C) AppleWebKit /533.3 (Khtml, like Gheko) QtCarBrowser Safari/533.3');

        // unknown device
        $this->trackDeviceVisit($t, Date::factory($this->dateTime)->addHour(75.1)->getDatetime(), 'Mozilla/5.0 (Android; Linux armv7l; rv:10.0) Gecko/20120118 Firefox/10.0 Fennec/10.0');

        // smartphone
        $this->trackDeviceVisit($t, Date::factory($this->dateTime)->addHour(79.5)->getDatetime(), 'Mozilla/5.0 (Linux; U; Android 4.1.2; en-us; ADR910L 4G Build/JZO54K) AppleWebKit/534.30 (KHTML, like Gecko) Version/4.0 Mobile Safari/534.30');
        $this->trackDeviceVisit($t, Date::factory($this->dateTime)->addHour(86.8)->getDatetime(), 'Mozilla/5.0 (Linux; U; Android 4.1.2; zh-cn; ZTE N799D Build/JZO54K) AppleWebKit/534.30 (KHTML, like Gecko) Version/4.0 Mobile Safari/534.30; 360browser(securitypay,securityinstalled); 360(android,uppayplugin); 360 Aphone Browser (5.4.0)');

        $this->trackVisitSmartphone($t, Date::factory($this->dateTime)->addHour(101.6)->getDatetime());
        $this->trackVisitTablet($t, Date::factory($this->dateTime)->addHour(156.9)->getDatetime());
    }

    public function tearDown()
    {
        // empty
    }

    private function trackVisitSmartphone(\PiwikTracker $t, $dateTime)
    {
        $t->setForceVisitDateTime($dateTime);
        $t->setUserAgent('Mozilla/5.0 (Linux; U; Android 4.4.2; fr-fr; HTC One_M8 Build/KOT49H) AppleWebKit/537.16 (KHTML, like Gecko) Version/4.0 Mobile Safari/537.16');

        $t->setCountry('jp');
        $t->setRegion("40");
        $t->setCity('Tokyo');
        $t->setLatitude(35.70);
        $t->setLongitude(139.71);

        $t->setUrl('http://example.org/');
        $t->setGenerationTime(251);
        $t->setDebugStringAppend('bw_bytes=12053');
        self::checkResponse($t->doTrackPageView('home'));

        $t->doTrackContentImpression('product slider', 'product_16.jpg', 'http://example.org/product16');

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.05)->getDatetime());
        $t->doTrackEvent('product slider', 'next');
        $t->doTrackContentImpression('product slider', 'product_42.jpg', 'http://example.org/product42');

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.08)->getDatetime());
        $t->doTrackContentInteraction('click', 'product slider', 'product_42.jpg', 'http://example.org/product42');

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.1)->getDatetime());
        $t->setUrl('http://example.org/product42');
        $t->setCustomTrackingParameter('dimension2', 'NZD');
        $t->setEcommerceView($sku = 'P42X4D', $name = 'product 42', $category = 'software', $price = 60);
        $t->setGenerationTime(83);
        $t->setDebugStringAppend('bw_bytes=36053');
        self::checkResponse($t->doTrackPageView('product 42'));

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.14)->getDatetime());
        $t->doTrackAction('http://example.org/product42/productsheet.pdf', 'download');

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.2)->getDatetime());
        $t->setUrl('http://example.org/product42');
        $t->addEcommerceItem('P42X4D', 'product 42', 'software', $price = 60, $qty = 2);
        self::checkResponse($t->doTrackEcommerceOrder('X42FCY', 140, 120, 20, 6.9, 6.9));

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.25)->getDatetime());
        $t->setUrl('http://example.org/search');
        $t->setGenerationTime(318);
        $t->setDebugStringAppend('bw_bytes=2583');
        self::checkResponse($t->doTrackPageView('product search'));

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.29)->getDatetime());
        $t->doTrackSiteSearch('fancy product', '', 12560);

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.32)->getDatetime());
        $t->doTrackEvent('search', 'filter', 'enormous category');

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.35)->getDatetime());
        $t->doTrackSiteSearch('fancy product', 'enormous category', 13);

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.38)->getDatetime());
        $t->doTrackGoal(1);

        $t->setUrl('http://example.org/fancyproduct');
        $t->setEcommerceView($sku = 'F4NCYX', $name = 'fancy product', $category = 'software', $price = 40);
        $t->setGenerationTime(151);
        $t->setDebugStringAppend('bw_bytes=68895');
        self::checkResponse($t->doTrackPageView('fancy product'));

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.4)->getDatetime());
        $t->addEcommerceItem('F4NCYX', 'fancy product', 'software', $price = 40, $qty = 2);
        self::checkResponse($t->doTrackEcommerceCartUpdate(140));

        $t->setUrl('http://example.org/cart');
        $t->setGenerationTime(33);
        $t->setDebugStringAppend('bw_bytes=1590');
        self::checkResponse($t->doTrackPageView('cart'));

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.5)->getDatetime());
        self::checkResponse($t->doTrackAction('http://vendor.site', 'link'));
    }

    private function trackVisitTablet(\PiwikTracker $t, $dateTime)
    {
        $t->setForceVisitDateTime($dateTime);
        $t->setUserAgent('Mozilla/5.0 (Linux; U; en-us; KFAPWI Build/JDQ39) AppleWebKit/535.19 (KHTML, like Gecko) Silk/3.8 Safari/535.19 Silk-Accelerated=true');

        $t->setUrlReferrer('http://www.google.com/search?q=product%2042');
        $t->setUrl('http://example.org/product42');
        $t->setGenerationTime(96);
        $t->setDebugStringAppend('bw_bytes=6851');
        $t->setCustomVariable(1, 'custom', 'variable', 'page');
        $t->setCustomTrackingParameter('dimension1', '42');
        $t->setCustomTrackingParameter('dimension2', '€');
        self::checkResponse($t->doTrackPageView('product 42'));

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.09)->getDatetime());
        $t->setUrl('http://example.org/cart');
        $t->addEcommerceItem('P42X4D', 'product 42', 'software', $price = 60, $qty = 1);
        self::checkResponse($t->doTrackEcommerceOrder('R52Z6P', 66, 60, 6, 0, 0));

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.2)->getDatetime());
        $t->setUrl('http://example.org/');
        $t->setGenerationTime(511);
        $t->setDebugStringAppend('bw_bytes=2012');
        self::checkResponse($t->doTrackPageView('home'));

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.2)->getDatetime());
        $t->setUrl('http://example.org/');
        $t->setGenerationTime(511);
        $t->setDebugStringAppend('bw_bytes=2012');
        self::checkResponse($t->doTrackPageView('home'));

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.2)->getDatetime());
        $t->setUrl('http://example.org/');
        $t->setGenerationTime(222);
        $t->setDebugStringAppend('bw_bytes=950');
        self::checkResponse($t->doTrackPageView('home'));

        $t->doTrackContentImpression('product slider', 'product_16.jpg', 'http://example.org/product16');
    }

    private function trackVisitMediaPlayer(\PiwikTracker $t, $dateTime)
    {
        $t->setForceVisitDateTime($dateTime);
        $t->setUserAgent('Mozilla/5.0 (iPod; U; CPU iPhone OS 4_2_1 like Mac OS X; ja-jp) AppleWebKit/533.17.9 (KHTML, like Gecko) Mobile/8C148');

        $t->setUrlReferrer('http://www.nice.website/page3');
        $t->setCustomVariable(1, 'promo', 'summer', 'visit');
        $t->setCustomTrackingParameter('dimension1', '16');
        $t->setUrl('http://example.org/');
        $t->setGenerationTime(195);
        $t->setDebugStringAppend('bw_bytes=631');
        self::checkResponse($t->doTrackPageView('home'));

        $t->doTrackContentImpression('product slider', 'product_16.jpg', 'http://example.org/product16');

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.4)->getDatetime());
        $t->addEcommerceItem('F4NCYX', 'fancy product', 'software', $price = 40, $qty = 3);
        self::checkResponse($t->doTrackEcommerceCartUpdate(120));

        $t->setForceVisitDateTime(Date::factory($dateTime)->addHour(0.6)->getDatetime());
        $t->setUrl('');
        $t->setGenerationTime(156);
        $t->setDebugStringAppend('bw_bytes=1254');
        self::checkResponse($t->doTrackPageView('Action without url'));
    }

    private function trackDeviceVisit(\PiwikTracker $t, $dateTime, $useragent)
    {
        $t->setForceVisitDateTime($dateTime);
        $t->setUserAgent($useragent);

        $t->setUrl('http://example.org/');
        $t->setGenerationTime(111);
        $t->setDebugStringAppend('bw_bytes=555');
        self::checkResponse($t->doTrackPageView('home'));

        $t->doTrackContentImpression('product slider', 'product_16.jpg', 'http://example.org/product16');
    }
}