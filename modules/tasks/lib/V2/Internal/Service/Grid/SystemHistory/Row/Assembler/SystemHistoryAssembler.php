<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Row\Assembler;

use Bitrix\Main\Grid\Row\RowAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Column\DataProvider\SystemHistoryProvider;
use Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Row\Assembler\Field\ErrorsFieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Row\Assembler\Field\MessageFieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Row\Assembler\Field\TimeFieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Row\Assembler\Field\TypeFieldAssembler;

class SystemHistoryAssembler extends RowAssembler
{
	protected function prepareFieldAssemblers(): array
	{
		return [
			new TypeFieldAssembler([SystemHistoryProvider::TYPE_COLUMN]),
			new TimeFieldAssembler([SystemHistoryProvider::TIME_COLUMN]),
			new MessageFieldAssembler([SystemHistoryProvider::MESSAGE_COLUMN]),
			new ErrorsFieldAssembler([SystemHistoryProvider::ERRORS_COLUMN]),
		];
	}
}
