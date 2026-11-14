<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Deal;

use Bitrix\Crm\Settings\DealSettings;
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
	public const ID = 'crm.deal.product_rows_export.enabled';

	private readonly DealSettings $dealSettings;

	public function __construct(?DealSettings $dealSettings = null)
	{
		$this->dealSettings = $dealSettings ?? DealSettings::getCurrent();
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
		return $this->dealSettings->isProductRowExportEnabled();
	}

	protected function apply(mixed $value): Result
	{
		$this->dealSettings->enableProductRowExport($value);

		return new Result();
	}
}
