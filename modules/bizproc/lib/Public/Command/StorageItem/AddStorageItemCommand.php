<?php

namespace Bitrix\Bizproc\Public\Command\StorageItem;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItem;

class AddStorageItemCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $createdBy,
		public readonly int $storageTypeId,
		public readonly StorageItem $storageItem,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'createdBy' => $this->createdBy,
			'storageTypeId' => $this->storageTypeId,
			'storageItem' => $this->storageItem->toArray(),
		];
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			createdBy: $props['createdBy'],
			storageTypeId: $props['storageTypeId'],
			storageItem: StorageItem::mapFromArray($props['storageItem'], $props['storageTypeId']),
		);
	}

	protected function execute(): Result
	{
		try {
			return new StorageItemResult(
				storageItem: (new AddStorageItemCommandHandler())($this)
			);
		}
		catch (Exception $exception)
		{
			return (new StorageItemResult())->addError(ErrorBuilder::buildFromException($exception));
		}
	}
}
