<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\StorageList;

use Bitrix\Bizproc\Internal\Grid\StorageList\Column\Provider\StorageListDataProvider;
use Bitrix\Bizproc\Internal\Grid\StorageList\Row\Assembler\StorageListRowAssembler;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Pagination\PaginationFactory;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Main\UI\PageNavigation;

final class StorageListGrid extends Grid
{
	protected function createColumns(): Columns
	{
		return new Columns(
			new StorageListDataProvider(),
		);
	}

	protected function createRows(): Rows
	{
		return new Rows(
			new StorageListRowAssembler($this->getVisibleColumnsIds()),
		);
	}

	protected function createPagination(): ?PageNavigation
	{
		return (new PaginationFactory($this, $this->getPaginationStorage()))->create();
	}
}
