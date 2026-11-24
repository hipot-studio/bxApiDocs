<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule\Row\Assembler\Field;

use Bitrix\BIConnector\Internal\Grid\RowRule\Filter\RowRuleFilterProvider;
use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\UserFieldAssembler;

/**
 * The author of a rule with the avatar, as in the other grids of the workplace: a click puts them into the filter.
 */
final class CreatedByFieldAssembler extends UserFieldAssembler
{
	protected function prepareColumn($value): string
	{
		return $this->prepareColumnWithParams(
			$value,
			RowRuleFilterProvider::FIELD_AUTHOR,
			'BX.BIConnector.RowRulesGrid.Instance.handleCreatedByClick',
		);
	}
}
