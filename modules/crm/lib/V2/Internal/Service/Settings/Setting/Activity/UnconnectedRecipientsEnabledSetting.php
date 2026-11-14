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
final class UnconnectedRecipientsEnabledSetting extends AbstractSetting
{
	public const ID = 'crm.activity.unconnected_recipients.enabled';

	private readonly ActivitySettings $activitySettings;

	public function __construct(?ActivitySettings $activitySettings = null)
	{
		$this->activitySettings = $activitySettings ?? ActivitySettings::getCurrent();
	}

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
			'activity_enable_unconnected_recipients',
		);
	}

	public function getValue(): bool
	{
		return $this->activitySettings->getEnableUnconnectedRecipients();
	}

	protected function apply(mixed $value): Result
	{
		$this->activitySettings->setEnableUnconnectedRecipients($value);

		return new Result();
	}
}
