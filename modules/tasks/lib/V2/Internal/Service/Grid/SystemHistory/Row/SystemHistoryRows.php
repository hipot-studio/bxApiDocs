<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Row;

use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Row\Assembler\SystemHistoryAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Column\DataProvider\SystemHistoryProvider;

class SystemHistoryRows extends Rows
{
	public function __construct()
	{
		parent::__construct(new SystemHistoryAssembler([
			SystemHistoryProvider::TYPE_COLUMN,
			SystemHistoryProvider::TIME_COLUMN,
			SystemHistoryProvider::MESSAGE_COLUMN,
			SystemHistoryProvider::ERRORS_COLUMN,
		]));
	}
}