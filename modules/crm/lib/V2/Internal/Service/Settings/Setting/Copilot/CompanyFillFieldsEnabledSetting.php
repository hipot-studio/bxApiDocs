<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Copilot;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Settings\CopilotSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmOwnerType;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class CompanyFillFieldsEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.copilot.fill_fields.company.enabled';
	private const ERROR_CODE = 'SETTING_NOT_AVAILABLE';

	private readonly CopilotSettings $copilotSettings;

	public function __construct(?CopilotSettings $copilotSettings = null)
	{
		$this->copilotSettings = $copilotSettings ?? CopilotSettings::getCurrent();
	}

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getDescription(): SettingDescription
	{
		return new SettingDescription(
			self::CODE,
			SettingTitleProvider::getTitle(self::CODE),
			new BooleanSchema(),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): bool
	{
		return $this->copilotSettings->isFillFieldsEnabledFor(CCrmOwnerType::Company);
	}

	protected function apply(mixed $value): Result
	{
		if (!$this->isWriteAvailable())
		{
			return (new Result())->addError(
				new Error('Setting is not available for writing.', self::ERROR_CODE),
			);
		}

		$this->copilotSettings->setFillFieldsEnabledFor(CCrmOwnerType::Company, $value);

		return new Result();
	}

	private function isWriteAvailable(): bool
	{
		return $this->isCallProcessingAvailable() && $this->isGlobalSettingAvailable();
	}

	private function isCallProcessingAvailable(): bool
	{
		return AIManager::isAiCallProcessingEnabled();
	}

	private function isGlobalSettingAvailable(): bool
	{
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::FillItemFromCall);
	}
}
