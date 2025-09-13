<?php

declare(strict_types=1);

namespace Bitrix\Landing\Mainpage;

use Bitrix\Main\Application;

enum TemplateRegions: string
{
	case EnterpriseWestAr = 'alaio.vibe_enterprise_west_ar';
	case EnterpriseWestBr = 'alaio.vibe_enterprise_west_br';
	case EnterpriseWestDe = 'alaio.vibe_enterprise_west_de';
	case EnterpriseWestEn = 'alaio.vibe_enterprise_west_en';
	case EnterpriseWestFr = 'alaio.vibe_enterprise_west_fr';
	case EnterpriseWestId = 'alaio.vibe_enterprise_west_id';
	case EnterpriseWestIt = 'alaio.vibe_enterprise_west_it';
	case EnterpriseWestJa = 'alaio.vibe_enterprise_west_ja';
	case EnterpriseWestKz = 'alaio.vibe_enterprise_west_kz';
	case EnterpriseWestLa = 'alaio.vibe_enterprise_west_la';
	case EnterpriseWestMs = 'alaio.vibe_enterprise_west_ms';
	case EnterpriseWestPl = 'alaio.vibe_enterprise_west_pl';
	case EnterpriseWestTh = 'alaio.vibe_enterprise_west_th';
	case EnterpriseWestTr = 'alaio.vibe_enterprise_west_tr';
	case EnterpriseWestVn = 'alaio.vibe_enterprise_west_vn';
	//for zones 'cn', 'tc', 'sc'
	case EnterpriseChineseEn = 'alaio.vibe_enterprise_chinese_en';
	case EnterpriseChineseSc = 'alaio.vibe_enterprise_chinese_sc';
	case EnterpriseChineseTc = 'alaio.vibe_enterprise_chinese_tc';
	//for zones 'ru', 'by', 'kz'
	case EnterpriseRu = 'bitrix.vibe_enterprise_ru';

	public static function resolve(Templates $code): ?string
	{
		$regionCode = null;

		switch ($code)
		{
			case Templates::Enterprise:
				$portalZone = \CBitrix24::getPortalZone();
				$lang = Application::getInstance()->getContext()->getLanguage();
				if (in_array($portalZone, ['ru', 'by', 'kz', 'uz']))
				{
					$regionCode = self::EnterpriseRu;
				}
				elseif (in_array($portalZone, ['cn', 'tc', 'sc']))
				{
					$regionCodes = [
						'en' => self::EnterpriseChineseEn,
						'sc' => self::EnterpriseChineseSc,
						'tc' => self::EnterpriseChineseTc,
					];
					$regionCode = $regionCodes[$lang] ?? self::EnterpriseChineseEn;
				}
				else
				{
					$regionCodes = [
						'ar' => self::EnterpriseWestAr,
						'br' => self::EnterpriseWestBr,
						'de' => self::EnterpriseWestDe,
						'en' => self::EnterpriseWestEn,
						'fr' => self::EnterpriseWestFr,
						'id' => self::EnterpriseWestId,
						'it' => self::EnterpriseWestIt,
						'ja' => self::EnterpriseWestJa,
						'kz' => self::EnterpriseWestKz,
						'la' => self::EnterpriseWestLa,
						'ms' => self::EnterpriseWestMs,
						'pl' => self::EnterpriseWestPl,
						'th' => self::EnterpriseWestTh,
						'tr' => self::EnterpriseWestTr,
						'vn' => self::EnterpriseWestVn,
						'ru' => self::EnterpriseWestEn,
						'ua' => self::EnterpriseWestEn,
					];
					$regionCode = $regionCodes[$lang] ?? self::EnterpriseWestEn;
				}

				break;
		}

		return $regionCode?->value;
	}
}
