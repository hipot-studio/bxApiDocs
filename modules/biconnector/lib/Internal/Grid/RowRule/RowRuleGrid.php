<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule;

use Bitrix\BIConnector\Internal\Grid\RowRule\Column\Provider\RowRuleDataProvider;
use Bitrix\BIConnector\Internal\Grid\RowRule\Filter\RowRuleFilterProvider;
use Bitrix\BIConnector\Internal\Grid\RowRule\Row\Action\RowRuleActionDataProvider;
use Bitrix\BIConnector\Internal\Grid\RowRule\Row\Assembler\RowRuleRowAssembler;
use Bitrix\BIConnector\Internal\Grid\RowRule\Settings\RowRuleSettings;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Main\Localization\Loc;

/**
 * Row rules of the tables of BI Builder. Rows come from the rule provider page by page; the page size
 * is capped by the provider limit.
 *
 * @method RowRuleSettings getSettings()
 */
final class RowRuleGrid extends Grid
{
	public const GRID_ID = 'biconnector_row_rule_grid';
	public const DEFAULT_PAGE_SIZE = 20;

	public const PRESET_MY = 'my';
	public const PRESET_ACTIVE = 'active';
	public const PRESET_INACTIVE = 'inactive';

	public function __construct(RowRuleSettings $settings)
	{
		parent::__construct($settings);
		$this->getSettings()->setOrmFilter($this->getOrmFilter());
	}

	protected function createColumns(): Columns
	{
		return new Columns(
			new RowRuleDataProvider($this->getSettings()),
		);
	}

	protected function createRows(): Rows
	{
		return new Rows(
			new RowRuleRowAssembler($this->getVisibleColumnsIds(), $this->getSettings()),
			new RowRuleActionDataProvider(),
		);
	}

	protected function createFilter(): ?Filter
	{
		return new Filter(
			$this->getId(),
			new RowRuleFilterProvider($this->getSettings()),
		);
	}

	protected function getDefaultSorting(): array
	{
		return [RowRuleDataProvider::SORT_DATE_MODIFY => 'desc'];
	}

	public function getOrmOrder(): array
	{
		return parent::getOrmOrder() ?: $this->getDefaultSorting();
	}

	protected function getPageSizes(): array
	{
		return [10, self::DEFAULT_PAGE_SIZE, 50, 100];
	}

	public function hasUserFilter(): bool
	{
		return !empty($this->getOrmFilter());
	}

	/**
	 * Keeps the current page within the pages of the list: a page saved earlier may lie past the end
	 * after rules are deleted.
	 */
	public function clampCurrentPage(): void
	{
		$pagination = $this->getPagination();
		if ($pagination === null)
		{
			return;
		}

		$pageCount = max(1, $pagination->getPageCount());
		if ($pagination->getCurrentPage() > $pageCount)
		{
			$pagination->setCurrentPage($pageCount);
		}
	}

	/**
	 * The request of the rule provider for the current filter, order and page.
	 */
	public function getListRequest(): array
	{
		$pagination = $this->getPagination();

		return RowRuleListRequest::build(
			$this->getOrmFilter() ?? [],
			$this->getOrmOrder(),
			$pagination?->getOffset() ?? 0,
			$pagination?->getLimit() ?? self::DEFAULT_PAGE_SIZE,
		);
	}

	public static function getFilterPresets(int $userId, string $userName): array
	{
		return [
			self::PRESET_MY => [
				'name' => Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_PRESET_MY') ?? '',
				'sort' => 100,
				'fields' => [
					RowRuleFilterProvider::FIELD_AUTHOR => [$userId],
					RowRuleFilterProvider::FIELD_AUTHOR . '_label' => [$userName],
				],
			],
			self::PRESET_ACTIVE => [
				'name' => Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_PRESET_ACTIVE') ?? '',
				'sort' => 200,
				'fields' => [
					RowRuleFilterProvider::FIELD_IS_USED => RowRuleFilterProvider::VALUE_YES,
				],
			],
			self::PRESET_INACTIVE => [
				'name' => Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_PRESET_INACTIVE') ?? '',
				'sort' => 300,
				'fields' => [
					RowRuleFilterProvider::FIELD_IS_USED => RowRuleFilterProvider::VALUE_NO,
				],
			],
		];
	}
}
