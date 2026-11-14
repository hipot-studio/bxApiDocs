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
	public const ID = 'crm.recycle_bin.company.enabled';

	private readonly CompanySettings $companySettings;

	public function __construct(?CompanySettings $companySettings = null)
	{
		$this->companySettings = $companySettings ?? CompanySettings::getCurrent();
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
			'enable_company_recycle_bin',
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
