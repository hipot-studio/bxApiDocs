<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\StorageField;

use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Internal\Model\StorageFieldTable;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageFieldMapper;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Main\Localization\Loc;
use Bitrix\Bizproc\Public\Command\StorageField\StorageFieldDto;
use Bitrix\Bizproc\FieldType;

class FieldService
{
	private const CODE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

	public function prepare(StorageFieldDto $storageFieldDto): ?StorageField
	{
		$storageFieldEntity = StorageField::mapFromArray($storageFieldDto->toArray());
		$mapper = new StorageFieldMapper();
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

		$this->validateFieldConstraints($storageFieldEntity);

		return $storageFieldEntity;
	}

	private function validateFieldConstraints(StorageField $entity): void
	{
		$code = trim($entity->getCode());
		if ($code !== '' && !preg_match(self::CODE_PATTERN, $code))
		{
			throw new Exception(
				Loc::getMessage('BIZPROC_FIELD_SERVICE_WRONG_CODE') ?? ''
			);
		}

		$reservedFields = StorageFieldMapper::getFieldsMap();
		if (array_key_exists(mb_strtoupper($code), $reservedFields) && $reservedFields[mb_strtoupper($code)] !== null)
		{
			throw new Exception(
				Loc::getMessage('BIZPROC_FIELD_SERVICE_CODE_EXIST') ?? ''
			);
		}
	}

	public static function isNumericFieldType(string $type): bool
	{
		return in_array($type, [FieldType::INT, FieldType::DOUBLE, FieldType::USER, FieldType::FILE], true);
	}
}
