<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Lead;

use Bitrix\Crm\Settings\LeadSettings;
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
final class AutoReturnCustomerGenerationEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.lead.return_customer_auto_generation.enabled';
	private const ERROR_CODE = 'SETTING_NOT_AVAILABLE';

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
		return new SettingDescription(
			self::CODE,
			SettingTitleProvider::getTitle(self::CODE),
			new BooleanSchema(),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): bool
	{
		return $this->leadSettings->isAutoGenRcEnabled();
	}

	protected function apply(mixed $value): Result
	{
		if (!$this->isLeadAvailable())
		{
			return (new Result())->addError(
				new Error('Setting is not available for writing.', self::ERROR_CODE),
			);
		}

		$this->leadSettings->enableAutoGenRc($value);

		return new Result();
	}

	private function isLeadAvailable(): bool
	{
		return LeadSettings::isEnabled();
	}
}
