<?php

namespace Bitrix\Intranet\User\Grid\Row\Assembler\Field;

use Bitrix\Intranet\Entity;
use Bitrix\Main\Grid\Row\FieldAssembler;

class EmailFieldAssembler extends FieldAssembler
{
	protected function prepareRow(array $row): array
	{
		$row = parent::prepareRow($row);

		if (($row['data']['EXTERNAL_AUTH_ID'] ?? null) !== Entity\User::EXTERNAL_AUTH_ID_SYSTEM)
		{
			return $row;
		}

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = '';
		}

		return $row;
	}

	protected function prepareColumn($value): mixed
	{
		if ($this->getSettings()->isExcelMode())
		{
			return $value;
		}

		return '<a href="mailto:'.htmlspecialcharsbx($value).'">'.htmlspecialcharsbx($value).'</a>';
	}
}
