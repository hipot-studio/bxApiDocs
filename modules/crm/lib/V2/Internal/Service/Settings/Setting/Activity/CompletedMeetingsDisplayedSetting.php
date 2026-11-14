<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Activity;

use Bitrix\Crm\Settings\ActivitySettings;
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
final class CompletedMeetingsDisplayedSetting extends AbstractSetting
{
	public const ID = 'crm.activity.calendar.completed_meetings.displayed';
	private const ERROR_CODE = 'SETTING_NOT_AVAILABLE';

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
			'act_cal_show_compl_meeting',
		);
	}

	public function getValue(): bool
	{
		return ActivitySettings::getValue(ActivitySettings::KEEP_COMPLETED_MEETINGS);
	}

	protected function apply(mixed $value): Result
	{
		if (!$this->isWriteAvailable())
		{
			return (new Result())->addError(
				new Error('Setting is not available for writing.', self::ERROR_CODE),
			);
		}

		ActivitySettings::setValue(ActivitySettings::KEEP_COMPLETED_MEETINGS, $value);

		return new Result();
	}

	private function isWriteAvailable(): bool
	{
		return ActivitySettings::getValue(ActivitySettings::ENABLE_CALENDAR_EVENTS_SETTINGS);
	}
}
