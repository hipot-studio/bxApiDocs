<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Row\Assembler;

use Bitrix\Main\Grid\Row\RowAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Row\Assembler\Field\AuthorFieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Row\Assembler\Field\ChangesetLocationFieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Row\Assembler\Field\ChangesetValueFieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Row\Assembler\Field\TimeFieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Column\DataProvider\HistoryProvider;

class HistoryAssembler extends RowAssembler
{
	protected function prepareFieldAssemblers(): array
	{
		return [
			new TimeFieldAssembler([HistoryProvider::TIME_COLUMN]),
			new AuthorFieldAssembler([HistoryProvider::AUTHOR_COLUMN]),
			new ChangesetLocationFieldAssembler([HistoryProvider::CHANGE_TYPE_COLUMN]),
			new ChangesetValueFieldAssembler([HistoryProvider::CHANGE_VALUE_COLUMN]),
		];
	}
}
