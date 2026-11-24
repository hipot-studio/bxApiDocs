<?php
declare(strict_types=1);

namespace Bitrix\ImMobile\Update;

use Bitrix\ImMobile\Public\Feature\LegacyFeatureFlagRegistry;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\FeatureFlagTable;
use Bitrix\Main\Config\Option;

final class FeatureFlagTransfer
{
	private const MODULE_ID = 'immobile';
	private const TRANSFER_MARK_PREFIX = 'feature_flag_transferred_';

	public static function transferLegacyOptionsAgent(): string
	{
		$agentCall = __METHOD__ . '();';

		foreach (LegacyFeatureFlagRegistry::getOptionToFlagMap() as $optionName => $flagClass)
		{
			$transferMark = self::TRANSFER_MARK_PREFIX . $optionName;
			if (Option::get(self::MODULE_ID, $transferMark, 'N') === 'Y')
			{
				continue;
			}

			if (FeatureFlagTable::getByPrimary($flagClass)->fetch() === false)
			{
				Feature::clearCache();

				if (LegacyFeatureFlagRegistry::isLegacyOptionEnabled($optionName))
				{
					Feature::enable($flagClass);
				}
				else
				{
					Feature::disable($flagClass);
				}

				if (FeatureFlagTable::getByPrimary($flagClass)->fetch() === false)
				{
					return $agentCall;
				}
			}

			Option::set(self::MODULE_ID, $transferMark, 'Y');
		}

		return '';
	}
}
