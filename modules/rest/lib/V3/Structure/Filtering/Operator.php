<?php

namespace Bitrix\Rest\V3\Structure\Filtering;

enum Operator: string
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
