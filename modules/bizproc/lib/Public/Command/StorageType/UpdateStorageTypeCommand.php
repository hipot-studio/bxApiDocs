<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\StorageType;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Bizproc\Internal\Entity\StorageType\StorageType;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Main\Result;

class UpdateStorageTypeCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $updatedBy,
		public readonly StorageType $storageType,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'updatedBy' => $this->updatedBy,
			'storageType' => $this->storageType->toArray(),
		];
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			updatedBy: $props['updatedBy'],
			storageType: StorageType::mapFromArray($props['storageType']),
		);
	}

	protected function execute(): Result
	{
		try {
			return new StorageTypeResult(
				storageType: (new UpdateStorageTypeCommandHandler())($this)
			);
		}
		catch (Exception $exception)
		{
			return (new StorageTypeResult())->addError(ErrorBuilder::buildFromException($exception));
		}
	}
}
