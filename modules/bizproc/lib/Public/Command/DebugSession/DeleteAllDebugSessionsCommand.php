<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\DebugSession;

use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class DeleteAllDebugSessionsCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $userId,
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			$deletedCount = (new DeleteAllDebugSessionsCommandHandler())($this);

			return (new Result())->setData([
				'success' => true,
				'count' => $deletedCount,
			]);
		}
		catch (\Throwable $e)
		{
			return (new Result())->addError(ErrorBuilder::buildFromException($e));
		}
	}
}
