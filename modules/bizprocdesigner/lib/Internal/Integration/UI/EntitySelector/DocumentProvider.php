<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\UI\EntitySelector;

use Bitrix\Main\Loader;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;

/**
 * Entity-selector reflects this class with bizprocdesigner alone included, so nothing bizproc may be
 * named in its declaration. The implementation extends the bizproc provider and lives in a separate
 * file {@see ContributingDocumentProvider} loaded once the module is up.
 */
class DocumentProvider extends BaseProvider
{
	private ?BaseProvider $provider;

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->options = $options;
		$this->provider = Loader::includeModule('bizproc')
			? new ContributingDocumentProvider($options)
			: null
		;
	}

	public function isAvailable(): bool
	{
		return $this->provider?->isAvailable() ?? false;
	}

	public function getItems(array $ids): array
	{
		return $this->provider?->getItems($ids) ?? [];
	}

	public function getPreselectedItems(array $ids): array
	{
		return $this->provider?->getPreselectedItems($ids) ?? [];
	}

	public function fillDialog(Dialog $dialog): void
	{
		$this->provider?->fillDialog($dialog);
	}

	public function getChildren(Item $parentItem, Dialog $dialog): void
	{
		$this->provider?->getChildren($parentItem, $dialog);
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$this->provider?->doSearch($searchQuery, $dialog);
	}

	public function handleBeforeItemSave(Item $item): void
	{
		$this->provider?->handleBeforeItemSave($item);
	}
}
