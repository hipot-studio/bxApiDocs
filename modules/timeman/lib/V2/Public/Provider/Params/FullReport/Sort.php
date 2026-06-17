<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider\Params\FullReport;

use Bitrix\Timeman\V2\Public\Provider\Params\AbstractSort;

class Sort extends AbstractSort
{
	protected static function fieldsEnumClass(): string
	{
		return FieldsEnum::class;
	}
}
