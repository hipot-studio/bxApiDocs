<?php

namespace Bitrix\Bizproc\Activity\Enum;

enum Operator: string
{
	case Between = 'between';
	case Contain = 'contain';
	case Empty = 'empty';
	case Equal = '=';
	case GreaterThen = '>';
	case GreaterThenOrEqual = '>=';
	case In = 'in';
	case LessThen = '<';
	case LessThenOrEqual = '<=';
	case NotContain = '!contain';
	case NotEmpty = '!empty';
	case NotEqual = '!=';
	case NotIn = '!in';
	case Modified = 'modified';
}
