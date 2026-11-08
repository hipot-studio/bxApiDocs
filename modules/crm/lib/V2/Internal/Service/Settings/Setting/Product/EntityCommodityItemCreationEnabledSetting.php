<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Product;

use Bitrix\Crm\Settings\LayoutSettings;
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
final class EntityCommodityItemCreationEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.product.entity_commodity_item_creation.enabled';
	private const ERROR_CODE = 'SETTING_NOT_AVAILABLE';

	private readonly LayoutSettings $layoutSettings;

	public function __construct(?LayoutSettings $layoutSettings = null)
	{
		$this->layoutSettings = $layoutSettings ?? LayoutSettings::getCurrent();
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
		return $this->layoutSettings->isCreationEntityCommodityItemAllowed();
	}

	protected function apply(mixed $value): Result
	{
		if (!$this->isWriteAvailable())
		{
			return (new Result())->addError(
				new Error('Setting is not available for writing.', self::ERROR_CODE),
			);
		}

		$this->layoutSettings->enableEntityCommodityItemCreation($value);

		return new Result();
	}

	private function isWriteAvailable(): bool
	{
		return $this->layoutSettings->isCommonProductProcessingEnabled();
	}
}
