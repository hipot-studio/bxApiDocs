<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Provider\UsageStat\Params;

use Bitrix\Main\Provider\Params\Sort;

final class UsageStatSort extends Sort
{
	protected function getAllowedFields(): array
	{
		return [
			'ID',
			'TIMESTAMP_X',
			'REAL_TIME',
			'DATA_SIZE',
			'ROW_NUM',
			'LOAD_LEVEL',
		];
	}
}
