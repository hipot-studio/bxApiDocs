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
	public const ID = 'crm.activity.calendar.reassigned_meetings.kept';

	public function getId(): string
	{
		return self::ID;
	}

	public function getDescription(): SettingDescription
	{
		return new SettingDescription(
			self::ID,
			SettingTitleProvider::getTitle(self::ID),
			new BooleanSchema(),
			SettingTitleProvider::getDescription(self::ID),
			SettingTitleProvider::getNote(self::ID),
			'act_cal_keep_reassign_meeting',
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
