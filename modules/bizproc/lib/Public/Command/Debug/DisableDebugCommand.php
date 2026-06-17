<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Debug;

use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class DisableDebugCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly int $templateId,
		public readonly ?array $documentId = null,
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			(new DisableDebugCommandHandler())($this);

			return (new Result())->setData(['success' => true]);
		}
		catch (\Throwable $e)
		{
			return (new Result())->addError(ErrorBuilder::buildFromException($e));
		}
	}
}
