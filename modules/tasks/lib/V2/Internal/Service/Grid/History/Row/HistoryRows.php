<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Row;

use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Row\Assembler\HistoryAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Column\DataProvider\HistoryProvider;

class HistoryRows extends Rows
{
	public function __construct()
	{
		parent::__construct(new HistoryAssembler([
			HistoryProvider::TIME_COLUMN,
			HistoryProvider::AUTHOR_COLUMN,
			HistoryProvider::CHANGE_TYPE_COLUMN,
			HistoryProvider::CHANGE_VALUE_COLUMN,
		]));
	}
}
