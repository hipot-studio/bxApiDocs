<?php

namespace Bitrix\Bizproc\Internal\Service\StorageField;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItem;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;
use Bitrix\Bizproc\Public\Provider\Params\StorageField\StorageFieldFilter;
use Bitrix\Main\Localization\Loc;

class StorageFieldValidatorService
{
	private StorageFieldRepositoryInterface $repository;

	public function __construct(StorageFieldRepositoryInterface $repository)
	{
		$this->repository = $repository;
	}

	public function validate(int $storageTypeId, StorageItem $item): array
	{
		$fieldCollection = $this->repository->getList(filter: new StorageFieldFilter(['STORAGE_ID' => $storageTypeId]));
		$errors = [];

		$id = $item->getId() ?? false;
		$fields = $item->toArray();
		$documentService = \CBPRuntime::GetRuntime(true)->getDocumentService();
		$documentType = ['bizproc', 'CBPVirtualDocument', 'type_0'];
		foreach ($fieldCollection as $field)
		{
			$code = $field->getCode();
			$value = $item->getValueField($code);

			$fieldProperties = $documentService->getFieldTypeObject($documentType, $field->toProperty());
			if (!$fieldProperties)
			{
				continue;
			}

			if ($field->getType() === 'user')
			{
				$fields[$code] = \CBPHelper::extractUsers($fields[$code], $documentType);
			}

			$fieldErrors = [];
			$fieldProperties->extractValue(
				['Field' => $code],
				$fields,
				$fieldErrors
			);

			if (!empty($fieldErrors))
			{
				array_push($errors, ...$fieldErrors);
			}

			$property = $fieldProperties->getProperty();
			$isRequired = $property['Required'];
			$isSingleValue = !$property['Multiple'];

			if ($isRequired && (($id !== null && $id <= 0) || array_key_exists($code, $fields)))
			{
				$fieldName = $property['Name'];
				if ($isSingleValue)
				{
					if (\CBPHelper::isEmptyValue($value))
					{
						$errors[] = [
							'code' => 'ErrorValue',
							'message' => Loc::getMessage('BIZPROC_SERVICE_VALIDATOR_FIELD_VALUE_IS_MISSING', [
								'#FIELD_NAME#' => $fieldName
							]),
							'parameter' => $code,
						];
					}
				}
				else
				{
					if (!is_array($value))
					{
						$errors[] = [
							'code' => 'ErrorValue',
							'message' => Loc::getMessage('BIZPROC_SERVICE_VALIDATOR_FIELD_VALUE_IS_MISSING', [
								'#FIELD_NAME#' => $fieldName
							]),
							'parameter' => $code,
						];
					}
					else
					{
						$found = false;
						foreach ($value as $val)
						{
							if (
								(
									is_array($val)
									&& (implode('', $val) <> '')
								)
								||
								(
									!is_array($val)
									&& !\CBPHelper::isEmptyValue($val)
								)
							)
							{
								$found = true;
								break;
							}
						}
						if (!$found)
						{
							$errors[] = [
								'code' => 'ErrorValue',
								'message' => Loc::getMessage('BIZPROC_SERVICE_VALIDATOR_FIELD_VALUE_IS_MISSING', [
									'#FIELD_NAME#' => $fieldName
								]),
								'parameter' => $code,
							];
						}
					}
				}
			}
		}

		return $errors;
	}
}
