<?php

namespace Bitrix\Crm\Service\RegionalSettings\Maps\DaysBeforeClose;

use Bitrix\Crm\Service\RegionalSettings\Maps\BaseRegionalSettingMap;
use Bitrix\Crm\Service\RegionalSettings\RegionalSettings;

final class InvoiceDaysBeforeCloseRegionalMap extends BaseRegionalSettingMap
{
	public function getMap(): array
	{
		$regionPrefix = RegionalSettings::DIMENSION_REGION . RegionalSettings::VALUE_SEPARATOR;

		return [
//			$regionPrefix . 'ru' . RegionalSettings::DIMENSION_SEPARATOR . $culturePrefix . 'ru' => 12,
//			$regionPrefix . 'by' . RegionalSettings::DIMENSION_SEPARATOR . $culturePrefix . 'en' => 113,
//			$culturePrefix . 'ru' => 8,
//			$regionPrefix . 'by' => 3,
//			$regionPrefix . 'ru' => 3,
//			$regionPrefix . 'eu' => 3,
//			RegionalSettings::DEFAULT_KEY => 3,
		];
	}
}
