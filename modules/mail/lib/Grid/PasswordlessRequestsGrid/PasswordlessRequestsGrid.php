<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid;

use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Column\Provider\PasswordlessRequestsDataProvider;
use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Filter\PasswordlessRequestsFilter;
use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Filter\PasswordlessRequestsFilterSettings;
use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Filter\Provider\PasswordlessRequestsFilterDataProvider;
use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Assembler\PasswordlessRequestsRowAssembler;
use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\PasswordlessRequestsRows;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Pagination\LazyLoadTotalCount;
use Bitrix\Main\Grid\Pagination\PaginationFactory;
use Bitrix\Main\UI\PageNavigation;

final class PasswordlessRequestsGrid extends Grid
{
	use LazyLoadTotalCount;

	protected function createPagination(): ?PageNavigation
	{
		return (new PaginationFactory($this, $this->getPaginationStorage()))->create();
	}

	protected function createColumns(): Columns
	{
		return new Columns(
			new PasswordlessRequestsDataProvider(),
		);
	}

	protected function createRows(): PasswordlessRequestsRows
	{
		$rowAssembler = new PasswordlessRequestsRowAssembler(
			$this->getVisibleColumnsIds(),
			$this->getSettings(),
		);
		$actionsProvider = new Row\Action\PasswordlessRequestsActionProvider($this->getSettings());

		return new PasswordlessRequestsRows($rowAssembler, $actionsProvider);
	}

	protected function createFilter(): ?Filter
	{
		$params = [
			'ID' => $this->getId(),
		];
		$filterSettings = new PasswordlessRequestsFilterSettings($params);

		return new PasswordlessRequestsFilter(
			$this->getId(),
			new PasswordlessRequestsFilterDataProvider($filterSettings),
		);
	}
}
