<?php

namespace Bitrix\Bizproc\Public\Command\StorageField;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Exception\StorageField\CreateStorageFieldException;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;

class AddStorageFieldCommandHandler
{
	private StorageFieldRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageFieldRepository();
	}

	public function __invoke(AddStorageFieldCommand $command): StorageField
	{
		$storageField = $command->storageField;
		if ($storageField->getType() === FieldType::FILE)
		{
			throw new CreateStorageFieldException(
				ErrorMessage::INVALID_PARAM_ARG->get(['#PARAM#' => 'type', '#VALUE#' => FieldType::FILE])
			);
		}

		// an add never updates an existing row, whatever id the caller passed in
		$storageField->setId(null);

		$result = $this->repository->save($storageField)->getData();

		$storageField->setCode($result['CODE']);

		return $storageField;
	}
}
