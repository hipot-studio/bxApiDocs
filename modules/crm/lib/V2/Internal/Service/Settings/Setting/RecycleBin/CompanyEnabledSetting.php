<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\RecycleBin;

use Bitrix\Crm\Settings\CompanySettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class CompanyEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.recycle_bin.company.enabled';

	private readonly CompanySettings $companySettings;

	public function __construct(?CompanySettings $companySettings = null)
	{
		$this->companySettings = $companySettings ?? CompanySettings::getCurrent();
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
		return $this->companySettings->isRecycleBinEnabled();
	}

	protected function apply(mixed $value): Result
	{
		$this->companySettings->enableRecycleBin($value);

		return new Result();
	}
}
