<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Conversion;

use Bitrix\Crm\Settings\ConversionSettings;
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
final class AutocreationEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.conversion.autocreation.enabled';
	private const ERROR_CODE = 'SETTING_NOT_AVAILABLE';

	private readonly ConversionSettings $conversionSettings;

	public function __construct(?ConversionSettings $conversionSettings = null)
	{
		$this->conversionSettings = $conversionSettings ?? ConversionSettings::getCurrent();
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
		return $this->areLeadsEnabled() ? $this->conversionSettings->isAutocreationEnabled() : true;
	}

	protected function apply(mixed $value): Result
	{
		if (!$this->areLeadsEnabled())
		{
			return (new Result())->addError(
				new Error('Setting is not available for writing.', self::ERROR_CODE),
			);
		}

		$this->conversionSettings->enableAutocreation($value);

		return new Result();
	}

	private function areLeadsEnabled(): bool
	{
		return LeadSettings::isEnabled();
	}
}
