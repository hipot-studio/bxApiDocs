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
final class ForwardedEmailAsOutgoingEnabledSetting extends AbstractSetting
{
	public const ID = 'crm.activity.forwarded_email_as_outgoing.enabled';

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
			'act_mark_fwd_emai_outgoing',
		);
	}

	public function getValue(): bool
	{
		return ActivitySettings::getValue(ActivitySettings::MARK_FORWARDED_EMAIL_AS_OUTGOING);
	}

	protected function apply(mixed $value): Result
	{
		ActivitySettings::setValue(ActivitySettings::MARK_FORWARDED_EMAIL_AS_OUTGOING, $value);

		return new Result();
	}
}
