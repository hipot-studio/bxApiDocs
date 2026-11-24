<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule\Row\Assembler;

use Bitrix\BIConnector\Internal\Grid\RowRule\Settings\RowRuleSettings;
use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\DateFieldAssembler;
use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\TrimmedTextFieldAssembler;
use Bitrix\Main\Grid\Row\RowAssembler;

final class RowRuleRowAssembler extends RowAssembler
{
	public function __construct(array $visibleColumnIds, private readonly ?RowRuleSettings $settings = null)
	{
		parent::__construct($visibleColumnIds);
	}

	protected function prepareFieldAssemblers(): array
	{
		return [
			new TrimmedTextFieldAssembler(['TABLE', 'FIELD', 'DESCRIPTION']),
			new Field\CreatedByFieldAssembler(['CREATED_BY_ID'], $this->settings),
			new Field\UpdatedByFieldAssembler(['UPDATED_BY_ID'], $this->settings),
			new Field\ConditionFieldAssembler(['CONDITION']),
			new Field\ValuesFieldAssembler(['VALUES']),
			new DateFieldAssembler(['DATE_CREATE', 'DATE_MODIFY']),
			new Field\IsUsedFieldAssembler(['IS_USED']),
		];
	}
}
