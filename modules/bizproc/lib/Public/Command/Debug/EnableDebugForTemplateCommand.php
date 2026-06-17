<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Debug;

use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class EnableDebugForTemplateCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly int $templateId,
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			$debug = (new EnableDebugForTemplateCommandHandler())($this);

			return (new Result())->setData(['debug' => $debug]);
		}
		catch (\Throwable $e)
		{
			return (new Result())->addError(ErrorBuilder::buildFromException($e));
		}
	}
}
