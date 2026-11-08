<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Notification;

use Bitrix\Crm\MessageSender\SettingsManager;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingOption;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SelectSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<string>
 * @internal
 */
final class SenderSetting extends AbstractSetting
{
	public const CODE = 'crm.notifications.sender';

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getDescription(): SettingDescription
	{
		$options = [];
		foreach ($this->getSettingsList() as $value => $title)
		{
			$options[] = new SettingOption((string)$value, $title);
		}

		return new SettingDescription(
			self::CODE,
			SettingTitleProvider::getTitle(self::CODE),
			new SelectSchema(...$options),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): string
	{
		return SettingsManager::getValue();
	}

	protected function apply(mixed $value): Result
	{
		SettingsManager::setValue($value);

		return new Result();
	}

	/** @return array<string, string> */
	private function getSettingsList(): array
	{
		return SettingsManager::getSettingsList();
	}
}
