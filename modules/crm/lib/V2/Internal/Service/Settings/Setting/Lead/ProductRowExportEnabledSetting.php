<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Lead;

use Bitrix\Crm\Settings\LeadSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class ProductRowExportEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.lead.product_rows_export.enabled';

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
		return $this->leadSettings->isProductRowExportEnabled();
	}

	protected function apply(mixed $value): Result
	{
		$this->leadSettings->enableProductRowExport($value);

		return new Result();
	}
}
