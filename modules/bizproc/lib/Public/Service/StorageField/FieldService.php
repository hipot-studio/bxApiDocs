<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\StorageField;

use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Internal\Model\StorageFieldTable;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Exception\Exception;

class FieldService
{
	public function prepare(array $field): ?StorageField
	{
		$storageFieldEntity = StorageField::mapFromArray($field);
		$mapper = new \Bitrix\Bizproc\Internal\Repository\Mapper\StorageFieldMapper();
		$storageFieldOrm = $mapper->convertToOrm($storageFieldEntity);
		$storageFieldEntity->setCode($storageFieldOrm->getCode());
		$attributes = StorageFieldTable::getMap();
		$data = $storageFieldEntity->toArray();
		$map = $mapper::getFieldsMap();

		foreach ($attributes as $attribute)
		{
			$attributeName = $attribute->getName();
			if ($attributeName === 'STORAGE_ID' || !$attribute->isRequired())
			{
				continue;
			}

			if (
				!array_key_exists($map[$attributeName], $data)
				|| $data[$map[$attributeName]] === null
				|| $data[$map[$attributeName]] === ''
			)
			{
				$errorMessage = ErrorMessage::PARAM_REQUIRED->get(['#NAME#' => $attribute->getTitle()]);
				throw new Exception($errorMessage);
			}
		}

		return $storageFieldEntity;
	}
}
