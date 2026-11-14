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
	public const ID = 'crm.lead.product_rows_export.enabled';

	private readonly LeadSettings $leadSettings;

	public function __construct(?LeadSettings $leadSettings = null)
	{
		$this->leadSettings = $leadSettings ?? LeadSettings::getCurrent();
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
