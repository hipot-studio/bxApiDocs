<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;

final class ConditionFieldAssembler extends FieldAssembler
{
	private const OPERATOR_EQUAL = 'equal';
	private const OPERATOR_IN = 'in';

	protected function prepareColumn($value): string
	{
		$title = match ($value)
		{
			self::OPERATOR_EQUAL => Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_CONDITION_EQUAL'),
			self::OPERATOR_IN => Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_CONDITION_IN'),
			default => null,
		};

		return htmlspecialcharsbx((string)$title);
	}
}
