<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule\Row\Assembler\Field;

use Bitrix\BIConnector\Internal\Grid\RowRule\Filter\RowRuleFilterProvider;
use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\UserFieldAssembler;

/**
 * The user who changed a rule last, with the avatar: a click puts them into the filter.
 */
final class UpdatedByFieldAssembler extends UserFieldAssembler
{
	protected function prepareColumn($value): string
	{
		return $this->prepareColumnWithParams(
			$value,
			RowRuleFilterProvider::FIELD_EDITOR,
			'BX.BIConnector.RowRulesGrid.Instance.handleUpdatedByClick',
		);
	}
}
