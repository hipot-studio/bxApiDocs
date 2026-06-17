<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Assembler\Field;

use Bitrix\Mail\Helper\Dto\PasswordlessRequestsGrid\PasswordlessRequestRowDto;
use Bitrix\Main\Grid\Row\FieldAssembler;

abstract class CustomPasswordlessFieldAssembler extends FieldAssembler
{
	/**
	 * @param array{
	 *     data: array,
	 *     columns?: array<string, mixed>,
	 * } $row
	 * @return array{
	 *     data: array,
	 *     columns: array<string, mixed>,
	 * }
	 */
	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];
		$dto = PasswordlessRequestRowDto::fromGridRow($row['data']);

		foreach ($this->getColumnIds() as $columnId)
		{
			if ($this->getSettings()->isExcelMode())
			{
				$row['columns'][$columnId] = $this->prepareColumnForExport($dto);
			}
			else
			{
				$row['columns'][$columnId] = $this->prepareColumn($row['data']);
			}
		}

		return $row;
	}

	protected function prepareColumnForExport(PasswordlessRequestRowDto $dto): string
	{
		return $this->prepareColumn($dto->toGridRow());
	}
}
