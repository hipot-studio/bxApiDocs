<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params;

enum FilterOperator: string
{
	case Equal = 'equal';
	case NotEqual = 'notEqual';
	case Greater = 'greater';
	case GreaterOrEqual = 'greaterOrEqual';
	case Less = 'less';
	case LessOrEqual = 'lessOrEqual';
	case In = 'in';
	case Like = 'like';

	public static function getDefaultMapToRepositoryOperator(): array
	{
		return [
			self::Equal->value => '=',
			self::NotEqual->value => '!=',
			self::Greater->value => '>',
			self::GreaterOrEqual->value => '>=',
			self::Less->value => '<',
			self::LessOrEqual->value => '<=',
			self::In->value => 'in',
			self::Like->value => 'like',
		];
	}
}