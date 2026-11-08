<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Lead;

use Bitrix\Crm\Activity\Provider\ProviderManager;
use Bitrix\Crm\Settings\LeadSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingOption;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\MultiSelectSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<list<string>>
 * @internal
 */
final class CompletedActivityProviderCodesSetting extends AbstractSetting
{
	public const CODE = 'crm.lead.conversion.completed_activity_provider_codes';

	private readonly LeadSettings $leadSettings;

	public function __construct(?LeadSettings $leadSettings = null)
	{
		$this->leadSettings = $leadSettings ?? LeadSettings::getCurrent();
	}

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getDescription(): SettingDescription
	{
		$options = [];
		foreach ($this->getProviderList() as $provider)
		{
			$options[] = new SettingOption((string)$provider['ID'], (string)$provider['NAME']);
		}

		return new SettingDescription(
			self::CODE,
			SettingTitleProvider::getTitle(self::CODE),
			new MultiSelectSchema(...$options),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): array
	{
		$config = $this->leadSettings->getActivityCompletionConfig();
		$selectedCodes = [];
		foreach ($this->getProviderList() as $provider)
		{
			$code = (string)$provider['ID'];
			if (!isset($config[$code]) || (bool)$config[$code])
			{
				$selectedCodes[] = $code;
			}
		}

		return $selectedCodes;
	}

	protected function apply(mixed $value): Result
	{
		$config = $this->leadSettings->getActivityCompletionConfig();
		foreach ($this->getProviderList() as $provider)
		{
			$code = (string)$provider['ID'];
			$config[$code] = in_array($code, $value, true);
		}
		$this->leadSettings->setActivityCompletionConfig($config);

		return new Result();
	}

	private function getProviderList(): array
	{
		return ProviderManager::getCompletableProviderList();
	}
}
