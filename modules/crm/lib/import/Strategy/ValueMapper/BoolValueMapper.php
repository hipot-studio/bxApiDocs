<?php

namespace Bitrix\Crm\Import\Strategy\ValueMapper;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Main\Localization\Loc;

final class BoolValueMapper
{
	public function __construct(
		private readonly string $fieldId,
		private readonly ?bool $defaultValue = null,
	)
	{
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId($this->fieldId);
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$yesValues = [
			mb_strtoupper(Loc::getMessage('MAIN_YES')),
			'Y',
		];

		$noValues = [
			mb_strtoupper(Loc::getMessage('MAIN_NO')),
			'N',
		];

		$importValue = mb_strtoupper($row[$columnIndex] ?? '');
		$processedValue = match (true) {
			in_array($importValue, $yesValues, true) => true,
			in_array($importValue, $noValues, true) => false,
			default => $this->defaultValue,
		};

		if ($processedValue === null)
		{
			return FieldProcessResult::skip();
		}

		$importItemFields[$this->fieldId] = $processedValue;

		return FieldProcessResult::success();
	}
}
