<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;

final class IsUsedFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value): string
	{
		$code = $value ? 'BICONNECTOR_ROW_RULE_GRID_IS_USED_YES' : 'BICONNECTOR_ROW_RULE_GRID_IS_USED_NO';

		return htmlspecialcharsbx((string)Loc::getMessage($code));
	}
}
