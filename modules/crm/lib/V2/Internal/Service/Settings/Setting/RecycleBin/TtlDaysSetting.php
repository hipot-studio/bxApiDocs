<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\RecycleBin;

use Bitrix\Crm\Settings\RecyclebinSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingOption;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SelectSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Error;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<int>
 * @internal
 */
final class TtlDaysSetting extends AbstractSetting
{
	public const CODE = 'crm.recycle_bin.ttl_days';
	private const ERROR_CODE = 'SETTING_NOT_AVAILABLE';

	private readonly RecyclebinSettings $recyclebinSettings;

	public function __construct(?RecyclebinSettings $recyclebinSettings = null)
	{
		$this->recyclebinSettings = $recyclebinSettings ?? RecyclebinSettings::getCurrent();
	}

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getDescription(): SettingDescription
	{
		$options = [];
		foreach ($this->recyclebinSettings::getTtlValues() as $value => $title)
		{
			$options[] = new SettingOption((int)$value, $title);
		}

		return new SettingDescription(
			self::CODE,
			SettingTitleProvider::getTitle(self::CODE),
			new SelectSchema(...$options),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): int
	{
		return $this->recyclebinSettings->getTtl();
	}

	protected function apply(mixed $value): Result
	{
		if (!$this->isWriteAvailable())
		{
			return (new Result())->addError(
				new Error('Setting is not available for writing.', self::ERROR_CODE),
			);
		}

		$this->recyclebinSettings->setTtl($value);

		return new Result();
	}

	private function isWriteAvailable(): bool
	{
		return !ModuleManager::isModuleInstalled('bitrix24');
	}
}
