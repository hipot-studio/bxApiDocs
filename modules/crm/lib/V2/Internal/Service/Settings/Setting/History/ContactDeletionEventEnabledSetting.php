<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\History;

use Bitrix\Crm\Settings\HistorySettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class ContactDeletionEventEnabledSetting extends AbstractSetting
{
	public const ID = 'crm.history.contact_deletion_event.enabled';

	private readonly HistorySettings $historySettings;

	public function __construct(?HistorySettings $historySettings = null)
	{
		$this->historySettings = $historySettings ?? HistorySettings::getCurrent();
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
			'history_enable_contact_rm_event',
		);
	}

	public function getValue(): bool
	{
		return $this->historySettings->isContactDeletionEventEnabled();
	}

	protected function apply(mixed $value): Result
	{
		$this->historySettings->enableContactDeletionEvent($value);

		return new Result();
	}
}
