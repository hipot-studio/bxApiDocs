<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StorageField;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\BaseType\File;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Container;
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
		$storedItem = null;
		$fields = $item->toArray();
		$documentService = \CBPRuntime::GetRuntime(true)->getDocumentService();
		$documentType = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();
		foreach ($fieldCollection as $field)
		{
			$code = $field->getCode();
			$value = $item->getValueField($code);

			$fieldProperties = $documentService->getFieldTypeObject($documentType, $field->toProperty());
			if (!$fieldProperties)
			{
				continue;
			}

			if ($field->getType() === FieldType::USER)
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

			if ($field->getType() === FieldType::FILE && !\CBPHelper::isEmptyValue($value))
			{
				$storedItem ??= $this->loadStoredItem($storageTypeId, (int)$id);
				$foreignFileId = $this->findForeignFileId($value, $storedItem?->getValueField($code));
				if ($foreignFileId !== null)
				{
					$errors[] = [
						'code' => 'ErrorValue',
						'message' => ErrorMessage::INVALID_PARAM_ARG->get([
							'#PARAM#' => $property['Name'],
							'#VALUE#' => $foreignFileId,
						]),
						'parameter' => $code,
					];
				}
			}

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

	private function loadStoredItem(int $storageTypeId, int $itemId): ?StorageItem
	{
		if ($itemId <= 0)
		{
			return null;
		}

		return Container::getStorageItemRepository()?->getItem($storageTypeId, $itemId, ['*']);
	}

	/**
	 * Only files uploaded through bizproc may be newly referenced: the storage grid serves
	 * any referenced file without checking the viewer's rights to it.
	 */
	private function findForeignFileId(mixed $value, mixed $storedValue): ?string
	{
		$fileIds = File::toIdList($value);
		if ($fileIds === null)
		{
			return \CBPHelper::stringify($value);
		}

		$storedIds = array_map('strval', \CBPHelper::flatten($storedValue));
		foreach ($fileIds as $fileId)
		{
			if (in_array($fileId, $storedIds, true) || $this->isBizprocFile($fileId))
			{
				continue;
			}

			return $fileId;
		}

		return null;
	}

	private function isBizprocFile(string $fileId): bool
	{
		$file = preg_match('/^[0-9]+$/D', $fileId) === 1 ? \CFile::GetByID((int)$fileId)->Fetch() : false;

		return ($file['MODULE_ID'] ?? null) === 'bizproc';
	}
}
