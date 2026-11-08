<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Requisite\Param;

enum BankRequisiteFilterOperator: string
{
	case Equal = '=';
	case NotEqual = '!=';
	case Greater = '>';
	case GreaterOrEqual = '>=';
	case Less = '<';
	case LessOrEqual = '<=';
	case In = 'in';
	case Between = 'between';
}
