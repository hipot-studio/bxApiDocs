<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Grid\Menu;

use Bitrix\Catalog\Config\Feature;
use Bitrix\Iblock\Grid\Entity\ElementSettings;
use CCatalogSku;

class ProductGridCreateButton
{
	public const BTN_PRODUCT = 'product';
	public const BTN_SERVICE = 'service';
	public const BTN_SECTION = 'section';
	public const BTN_SKU = 'sku';
	public const BTN_SET = 'set';
	public const BTN_GROUP = 'group';

	protected ElementSettings $settings;
	private string $catalogType;

	public function __construct(ElementSettings $settings, string $catalogType)
	{
		$this->settings = $settings;
		$this->catalogType = $catalogType;
	}

	public function __destruct()
	{
		unset($this->settings);
	}

	public function getSettings(): ElementSettings
	{
		return $this->settings;
	}

	/**
	 * @param string[]|null $allowed
	 * @return string[]
	 */
	public function getButtonIds(?array $allowed): array
	{
		$defaultButtonIds = $this->getDefaultButtonIds();
		if ($allowed === null)
		{
			return $defaultButtonIds;
		}

		return array_intersect($allowed, $defaultButtonIds);
	}

	public function getDefaultButtonIds(): array
	{
		$settings = $this->getSettings();

		if ($settings->isNewCardEnabled())
		{
			$result = [
				static::BTN_PRODUCT,
				static::BTN_SERVICE,
			];
			if ($settings->isAllowedIblockSections())
			{
				$result[] = static::BTN_SECTION;
			}

			return $result;
		}

		$result = [
			static::BTN_PRODUCT,
		];

		if ($this->catalogType === CCatalogSku::TYPE_FULL)
		{
			$result[] = static::BTN_SKU;
		}

		$result[] = static::BTN_SERVICE;

		// TODO: need add code for checking catalog type
		if (Feature::isProductSetsEnabled())
		{
			$result[] = static::BTN_SET;
			$result[] = static::BTN_GROUP;
		}
		if ($settings->isAllowedIblockSections())
		{
			$result[] = static::BTN_SECTION;
		}

		return $result;
	}
}
