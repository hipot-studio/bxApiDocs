<?php

namespace Bitrix\Crm\Integration\UI\EntitySelector;

use Bitrix\Crm\Integration\Catalog\EntitySelector\CrmCatalogProductProvider;
use Bitrix\Main\Loader;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\SearchQuery;

final class CatalogProductProvider extends BaseProvider
{
	public const ENTITY_ID = 'crm_catalog_product';

	private ?CrmCatalogProductProvider $catalogProvider = null;

	public function __construct(array $options = [])
	{
		parent::__construct();

		if (Loader::includeModule('catalog'))
		{
			$this->catalogProvider = new CrmCatalogProductProvider($options);
			$this->options = $this->catalogProvider->getOptions();
		}
	}

	public function isAvailable(): bool
	{
		return $this->catalogProvider?->isAvailable() ?? false;
	}

	public function getItems(array $ids): array
	{
		return $this->isAvailable() ? $this->catalogProvider->getItems($ids) : [];
	}

	public function getSelectedItems(array $ids): array
	{
		return $this->isAvailable() ? $this->catalogProvider->getSelectedItems($ids) : [];
	}

	public function fillDialog(Dialog $dialog): void
	{
		if ($this->isAvailable())
		{
			$this->catalogProvider->fillDialog($dialog);
		}
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		if ($this->isAvailable())
		{
			$this->catalogProvider->doSearch($searchQuery, $dialog);
		}
	}
}
