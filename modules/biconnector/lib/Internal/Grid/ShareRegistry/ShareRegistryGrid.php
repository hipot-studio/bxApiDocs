<?php

namespace Bitrix\BIConnector\Internal\Grid\ShareRegistry;

use Bitrix\BIConnector\Internal\Grid\ShareRegistry\Column\Provider\ShareRegistryDataProvider;
use Bitrix\BIConnector\Internal\Grid\ShareRegistry\Filter\ShareRegistryFilterProvider;
use Bitrix\BIConnector\Internal\Grid\ShareRegistry\Panel\ShareRegistryPanelProvider;
use Bitrix\BIConnector\Internal\Grid\ShareRegistry\Row\Action\ShareRegistryActionProvider;
use Bitrix\BIConnector\Internal\Grid\ShareRegistry\Row\Assembler\ShareRegistryRowAssembler;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Panel\Panel;
use Bitrix\Main\Grid\Row\Rows;

/**
 * Public links of the portal. The order and the page size are fixed: the registry provider sorts
 * working links first, pages hold 50 rows.
 */
final class ShareRegistryGrid extends Grid
{
	public const GRID_ID = 'biconnector_share_registry';
	public const PAGE_SIZE = 50;

	protected function createColumns(): Columns
	{
		return new Columns(
			new ShareRegistryDataProvider($this->getSettings()),
		);
	}

	protected function createRows(): Rows
	{
		return new Rows(
			new ShareRegistryRowAssembler(
				$this->getVisibleColumnsIds(),
				filteredAuthorId: $this->getFilteredAuthorId(),
			),
			new ShareRegistryActionProvider(),
		);
	}

	protected function createPanel(): ?Panel
	{
		return new Panel(new ShareRegistryPanelProvider());
	}

	protected function createFilter(): ?Filter
	{
		return new Filter(
			$this->getId(),
			new ShareRegistryFilterProvider($this->getSettings()),
		);
	}

	protected function getDefaultSorting(): array
	{
		return [];
	}

	public function getOrmOrder(): array
	{
		return [];
	}

	public function initPagination(int $totalRowsCount, ?string $navId = null): void
	{
		parent::initPagination($totalRowsCount, $navId);

		$this->getPagination()?->setPageSize(self::PAGE_SIZE);
	}

	protected function getPageSizes(): array
	{
		return [self::PAGE_SIZE];
	}

	public function hasUserFilter(): bool
	{
		return !empty($this->getOrmFilter());
	}

	private function getFilteredAuthorId(): ?int
	{
		$authorIds = (array)($this->getOrmFilter()[ShareRegistryFilterProvider::FIELD_AUTHOR] ?? []);

		return count($authorIds) === 1 ? (int)$authorIds[0] : null;
	}
}
