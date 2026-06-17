<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\StorageList\Row\Assembler;

use Bitrix\Bizproc\Internal\Grid\StorageList\Row\Assembler\Field\TitleFieldAssembler;
use Bitrix\Main\Grid\Row\RowAssembler;

class StorageListRowAssembler extends RowAssembler
{
	protected function prepareFieldAssemblers(): array
	{
		return [
			new TitleFieldAssembler(['TITLE']),
		];
	}
}
