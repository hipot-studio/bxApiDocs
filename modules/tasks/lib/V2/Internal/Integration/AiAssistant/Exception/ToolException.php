<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception;

use Bitrix\Main\SystemException;
use Throwable;

class ToolException extends SystemException
{
	public function __construct(
		string $action,
		string $message = '',
		int $code = 0,
		string $file = '',
		int $line = 0,
		Throwable $previous = null,
	)
	{
		parent::__construct(
			"Failed to execute the tool '{$action}': {$message}",
			$code,
			$file,
			$line,
			$previous,
		);
	}
}
