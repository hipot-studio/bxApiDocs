<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Counter;

use Bitrix\Crm\Settings\CounterSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingOption;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SelectSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class ActivityResponsibleUsedSetting extends AbstractSetting
{
	public const CODE = 'crm.counter.activity_responsible.used';

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getDescription(): SettingDescription
	{
		return new SettingDescription(
			self::CODE,
			SettingTitleProvider::getTitle(self::CODE),
			new SelectSchema(
				new SettingOption(
					true,
					SettingTitleProvider::getOptionTitle(self::CODE, 'activity'),
				),
				new SettingOption(
					false,
					SettingTitleProvider::getOptionTitle(self::CODE, 'entity'),
				),
			),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): bool
	{
		return CounterSettings::getInstance()->useActivityResponsible();
	}

	protected function apply(mixed $value): Result
	{
		CounterSettings::getInstance()->setUseActivityResponsible($value);

		return new Result();
	}
}
