<?php

namespace Bitrix\Bizproc\Public\Command\StorageField;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Internal\Exception\StorageField\UpdateStorageFieldException;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;

class UpdateStorageFieldCommandHandler
{
	private StorageFieldRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageFieldRepository();
	}

	public function __invoke(UpdateStorageFieldCommand $command): StorageField
	{
		$storageField = $command->storageField;
		$existStorageField = $this->repository->getById((int)$storageField->getId(), ['ID', 'TYPE']);
		if (!$existStorageField)
		{
			throw new UpdateStorageFieldException('Storage field not found');
		}

		if ($storageField->getType() === FieldType::FILE && $existStorageField->getType() !== FieldType::FILE)
		{
			throw new UpdateStorageFieldException(
				ErrorMessage::INVALID_PARAM_ARG->get(['#PARAM#' => 'type', '#VALUE#' => FieldType::FILE])
			);
		}

		$this->repository->save($storageField);

		return $storageField;
	}
}
