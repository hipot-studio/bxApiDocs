<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Activity;

use Bitrix\Crm\Settings\ActivitySettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class ReassignedMeetingsKeptSetting extends AbstractSetting
{
	public const CODE = 'crm.activity.calendar.reassigned_meetings.kept';

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
		return ActivitySettings::getValue(ActivitySettings::KEEP_REASSIGNED_MEETINGS);
	}

	protected function apply(mixed $value): Result
	{
		ActivitySettings::setValue(ActivitySettings::KEEP_REASSIGNED_MEETINGS, $value);

		return new Result();
	}
}
