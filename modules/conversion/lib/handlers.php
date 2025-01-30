<?php

namespace Bitrix\Conversion;

use Bitrix\Conversion\Internals\MobileDetect;

final class handlers
{
    public static function OnGetCountersInfo()
    {
        return [
            'conversion_visit_day' => ['MODULE' => 'conversion', 'GROUP' => 'day', 'NAME' => 'Day visits', 'UNIT' => ''],
        ];
    }

    public static function OnGetAttributesInfo()
    {
        return [
            'conversion_site' => ['MODULE' => 'conversion', 'NAME' => 'Site name', 'ITEMS' => [
                's1' => 'Fist site...',
            ]],
            'conversion_device' => ['MODULE' => 'conversion', 'NAME' => 'User device', 'ITEMS' => [
                'mobile' => 'Mobile device',
                'tablet' => 'Tablet computer',
            ]],
            'conversion_referrer_source' => ['MODULE' => 'conversion', 'GROUP' => 'source', 'NAME' => 'From source', 'ITEMS' => [
                'google' => 'Google',
                'yandex' => '',
                'mail.ru' => '',
                'yahoo' => '',
            ]],
        ];
    }

    //	public static function getAttributeGroupsInfo()
    //	{
    //		return array(
    //			'site'   => array('NAME' => ''),
    //			'device' => array('NAME' => ''),
    //			'source' => array('NAME' => ''),
    //		);
    //	}

    public static function OnSetDayContextAttributes(DayContext $context)
    {
        // Site

        if ($siteId = DayContext::getSiteId()) {
            $context->setAttribute('conversion_site', $siteId);
        }

        // Device

        $detect = new MobileDetect();

        if ($detect->isTablet()) {
            $context->setAttribute('conversion_device', 'tablet');
        } elseif ($detect->isMobile()) {
            $context->setAttribute('conversion_device', 'mobile');
        }

        // Referrer

        if ($referrer = $_SERVER['HTTP_REFERER']) { // TODO check if no other source is set (ex: seo yandex direct) maybe??
            foreach (
                [ // TODO
                    'google' => '#^https?://www\.google\.[a-z]{2,3}/#',
                    'bing' => '#^https?://www\.bing\.[a-z]{2,3}/#',
                    'yahoo' => '#^https?://r\.search\.yahoo\.[a-z]{2,3}/#',
                    'ask' => '#^https?://www\.ask\.[a-z]{2,3}/#',
                    'yandex' => '#^https?://yandex\.[a-z]{2,3}/#',
                    'mail.ru' => '#^https?://go\.mail\.ru/#',
                    'rambler' => '#^https?://nova\.rambler\.ru/#',
                ] as $name => $regexp) {
                if (preg_match($regexp, $referrer)) {
                    $context->setAttribute('conversion_referrer_source', $name);

                    break;
                }
            }
        }
    }

    public static function OnProlog()
    {
        DayContext::getInstance();
        //
        //		$asset = Asset::getInstance();
        //
        //		$asset->addString('
        //		<script type="text/javascript">
        //			(window.BX||top.BX).message({"BITRIX_CONVERSION_VARNAME":"'.DayContext::getVarName().'"});
        //		</script>
        //		', true, AssetLocation::AFTER_CSS);
        //
        //		$asset->addJs('/bitrix/js/conversion/ajax_counter.js');
    }
}
