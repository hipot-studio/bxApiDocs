<?php

namespace Bitrix\Crm\Import\Strategy\ValueMapper;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use CCrmUrlUtil;
use CFile;

final class FileValueMapper
{
	public function __construct(
		private readonly string $fieldId,
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

		$possibleFileUrl = $row[$columnIndex] ?? null;
		if (empty($possibleFileUrl))
		{
			return FieldProcessResult::skip();
		}

		if (
			CCrmUrlUtil::HasScheme($possibleFileUrl)
			&& CCrmUrlUtil::IsSecureUrl($possibleFileUrl)
		)
		{
			$file = CFile::MakeFileArray($possibleFileUrl);
			if (is_array($file) && empty(CFile::CheckImageFile($file)))
			{
				$importItemFields[$this->fieldId] = [
					...$file,
					'MODULE_ID' => 'crm',
				];
			}

			return FieldProcessResult::success();
		}

		return FieldProcessResult::skip();
	}
}
