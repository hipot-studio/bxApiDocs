<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Source;

use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\DetailLinkFieldAssembler;
use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\FieldAssemblerPencilTrait;
use Bitrix\Main\Grid\Row\Assembler\Field\StringFieldAssembler;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;

class DescriptionFieldAssembler extends FieldAssembler
{
	use FieldAssemblerPencilTrait;

	protected function prepareColumn($value): ?string
	{
		$result = htmlspecialcharsbx($value['DESCRIPTION'] ?? '');

		return $this->addPencil(
			'DESCRIPTION',
			$result,
			($value['ID'] ?? 0),
			(string)Loc::getMessage('BICONNECTOR_SUPERSET_GRID_SOURCE_DESCRIPTION_FIELD_ARIA'),
			(string)Loc::getMessage('BICONNECTOR_SUPERSET_GRID_SOURCE_DESCRIPTION_EDIT_EMPTY_ARIA'),
		);
	}

	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];

		foreach ($this->getColumnIds() as $columnId)
		{
			$value = $row['data'];
			$row['columns'][$columnId] = $this->prepareColumn($value);
		}

		return $row;
	}
}
