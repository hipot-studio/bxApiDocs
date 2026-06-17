<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider\Params\Report;

use Bitrix\Timeman\V2\Public\Provider\Params\AbstractSelect;

class Select extends AbstractSelect
{
	protected static function fieldsEnumClass(): string
	{
		return FieldsEnum::class;
	}
}
