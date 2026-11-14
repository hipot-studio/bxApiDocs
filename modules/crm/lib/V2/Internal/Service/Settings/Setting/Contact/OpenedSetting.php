<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Contact;

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
final class OpenedSetting extends AbstractSetting
{
	public const ID = 'crm.contact.opened';

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
			'contact_opened_flag',
		);
	}

	public function getValue(): bool
	{
		return $this->contactSettings->getOpenedFlag();
	}

	protected function apply(mixed $value): Result
	{
		$this->contactSettings->setOpenedFlag($value);

		return new Result();
	}
}
