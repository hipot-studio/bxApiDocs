<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\RecycleBin;

use Bitrix\Crm\Settings\ContactSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class ContactEnabledSetting extends AbstractSetting
{
	public const ID = 'crm.recycle_bin.contact.enabled';

	private readonly ContactSettings $contactSettings;

	public function __construct(?ContactSettings $contactSettings = null)
	{
		$this->contactSettings = $contactSettings ?? ContactSettings::getCurrent();
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
			'enable_contact_recycle_bin',
		);
	}

	public function getValue(): bool
	{
		return $this->contactSettings->isRecycleBinEnabled();
	}

	protected function apply(mixed $value): Result
	{
		$this->contactSettings->enableRecycleBin($value);

		return new Result();
	}
}
