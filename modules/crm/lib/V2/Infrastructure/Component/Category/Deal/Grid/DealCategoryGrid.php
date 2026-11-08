<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Category\Deal\Grid;

use Bitrix\Crm\V2\Infrastructure\Component\Category\Deal\Grid\Column\Provider\DealCategoryDataProvider;
use Bitrix\Main\Grid;

final class DealCategoryGrid extends Grid\Grid
{
	protected function createColumns(): Grid\Column\Columns
	{
		return new Grid\Column\Columns(new DealCategoryDataProvider());
	}

	protected function createRows(): Grid\Row\Rows
	{
		return new Grid\Row\Rows(
			new Row\Assembler\DealCategoryRowAssembler($this->getVisibleColumnsIds()),
			new Row\Action\DealCategoryDataProvider(),
		);
	}

	protected function createPanel(): Grid\Panel\Panel
	{
		return new Grid\Panel\Panel(
			new Panel\Action\DealCategoryDataProvider(),
		);
	}

	protected function getDefaultSorting(): array
	{
		return [
			'SORT' => 'ASC',
		];
	}
}
