<?php

namespace Bitrix\Bizproc\Public\Command\StorageItem;

use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class DeleteStorageItemCommand extends AbstractCommand
{
	public function __construct(public readonly int|array $id)
	{
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
		];
	}

	protected function execute(): Result
	{
		try
		{
			(new DeleteStorageItemCommandHandler())($this);

			return new Result();
		}
		catch (Exception $exception)
		{
			return (new Result())->addError(ErrorBuilder::buildFromException($exception));
		}
	}
}
