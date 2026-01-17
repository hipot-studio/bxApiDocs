<?php

namespace Bitrix\Bizproc\Public\Command\StorageField;

use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class DeleteStorageFieldCommand extends AbstractCommand
{
	public function __construct(public readonly int $id)
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
			(new DeleteStorageFieldCommandHandler())($this);

			return new Result();
		}
		catch (Exception $exception)
		{
			return (new Result())->addError(ErrorBuilder::buildFromException($exception));
		}
	}
}
