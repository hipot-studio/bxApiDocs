<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\StorageType;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Internal\Entity\StorageType\StorageType;

class AddStorageTypeCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $createdBy,
		public readonly StorageType $storageType,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'createdBy' => $this->createdBy,
			'storageType' => $this->storageType->toArray(),
		];
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			createdBy: $props['createdBy'],
			storageType: StorageType::mapFromArray($props['storageType']),
		);
	}

	protected function execute(): Result
	{
		try {
			return new StorageTypeResult(
				storageType: (new AddStorageTypeCommandHandler())($this)
			);
		}
		catch (Exception $exception)
		{
			return (new StorageTypeResult())->addError(ErrorBuilder::buildFromException($exception));
		}
	}
}
