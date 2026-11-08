<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Measure\Grid\Row;

use Bitrix\Main\Grid\Row\Assembler\Field\NumberFieldAssembler;
use Bitrix\Main\Grid\Row\Assembler\Field\StringFieldAssembler;
use Bitrix\Main\Grid\Row\RowAssembler;
use Bitrix\Main\Localization\Loc;

/**
 * @internal
 */
final class MeasureRowAssembler extends RowAssembler
{
	protected function prepareFieldAssemblers(): array
	{
		return [
			new NumberFieldAssembler(false, ['ID', 'CODE']),
			new StringFieldAssembler([
				'MEASURE_TITLE',
				'SYMBOL_RUS',
				'SYMBOL_INTL',
				'SYMBOL_LETTER_INTL',
			]),
		];
	}

	public function prepareRows(array $rowsList): array
	{
		$rowsList = parent::prepareRows($rowsList);
		$visibleColumns = array_fill_keys($this->getVisibleColumnIds(), true);

		foreach ($rowsList as &$row)
		{
			$data = $row['data'] ?? [];
			if (isset($visibleColumns['MEASURE_TITLE']))
			{
				$row['columns']['MEASURE_TITLE'] = $this->prepareTitleLink($data);
			}

			if (isset($visibleColumns['IS_DEFAULT']))
			{
				$row['columns']['IS_DEFAULT'] = (string)Loc::getMessage(
					($data['IS_DEFAULT'] ?? false) === true ? 'MAIN_YES' : 'MAIN_NO',
				);
			}
		}
		unset($row);

		return $rowsList;
	}

	private function prepareTitleLink(array $data): string
	{
		$title = htmlspecialcharsbx((string)($data['MEASURE_TITLE'] ?? ''));
		$href = htmlspecialcharsbx((string)($data['PATH_TO_MEASURE_EDIT'] ?? ''));

		return '<a href="' . $href . '">' . $title . '</a>';
	}
}
