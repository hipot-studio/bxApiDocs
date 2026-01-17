<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History;

use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Column\DataProvider\HistoryProvider;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Row\HistoryRows;

class HistoryGrid extends Grid
{
	protected function createColumns(): Columns
	{
		return new Columns(new HistoryProvider());
	}

	protected function createRows(): Rows
	{
		return new HistoryRows();
	}
}
