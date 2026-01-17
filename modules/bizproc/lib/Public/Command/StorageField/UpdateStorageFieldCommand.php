<?php

namespace Bitrix\Bizproc\Public\Command\StorageField;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Main\Result;

class UpdateStorageFieldCommand extends AbstractCommand
{
	public function __construct(
		public readonly StorageField $storageField,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'storageField' => $this->storageField->toArray(),
		];
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			storageField: StorageField::mapFromArray($props['storageField']),
		);
	}

	protected function execute(): Result
	{
		try {
			return new StorageFieldResult(
				storageField: (new UpdateStorageFieldCommandHandler())($this)
			);
		}
		catch (Exception $exception)
		{
			return (new StorageFieldResult())->addError(ErrorBuilder::buildFromException($exception));
		}
	}
}
