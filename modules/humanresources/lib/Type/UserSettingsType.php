<?php

namespace Bitrix\HumanResources\Type;

use Bitrix\HumanResources\Internals\Trait\ValuesTrait;

enum UserSettingsType: string
{
	use ValuesTrait;

	case BusinessProcExcludeNodes = 'BUSINESS_PROC_EXCLUDE_NODES';
	case ReportsExcludeNodes = 'REPORTS_AUTHORITY_EXCLUDE_NODES';
}
