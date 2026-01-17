<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory;

use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Column\DataProvider\SystemHistoryProvider;
use Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Row\SystemHistoryRows;

class SystemHistoryGrid extends Grid
{
	protected function createColumns(): Columns
	{
		return new Columns(new SystemHistoryProvider());
	}

	protected function createRows(): Rows
	{
		return new SystemHistoryRows();
	}
}
