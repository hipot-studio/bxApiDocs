<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Counter;

use Bitrix\Crm\Settings\Crm;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class ActivitylessItemsIncludedSetting extends AbstractSetting
{
	public const CODE = 'crm.counter.activityless_items.included';
	private const ERROR_CODE = 'SETTING_NOT_AVAILABLE';

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getDescription(): SettingDescription
	{
		return new SettingDescription(
			self::CODE,
			SettingTitleProvider::getTitle(self::CODE),
			new BooleanSchema(),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): bool
	{
		if ($this->isUniversalScenarioEnabled())
		{
			return false;
		}

		return \CCrmUserCounterSettings::GetValue(\CCrmUserCounterSettings::ReckonActivitylessItems, true);
	}

	protected function apply(mixed $value): Result
	{
		if ($this->isUniversalScenarioEnabled())
		{
			return (new Result())->addError(
				new Error('Setting is not available for writing.', self::ERROR_CODE),
			);
		}

		\CCrmUserCounterSettings::SetValue(\CCrmUserCounterSettings::ReckonActivitylessItems, $value);

		return new Result();
	}

	private function isUniversalScenarioEnabled(): bool
	{
		return Crm::isUniversalActivityScenarioEnabled();
	}
}
