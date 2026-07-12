<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Loader;
use Bitrix\Main\Validation\ValidationService;

Loader::requireModule('aiassistant');

abstract class BaseTool extends ToolContract
{
	public function __construct(
		private readonly ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

	/**
	 * @throws McpException
	 */
	protected function validate(object $dto): void
	{
		$validationResult = $this->validationService->validate($dto);

		if (!$validationResult->isSuccess())
		{
			$error = $validationResult->getError();

			$message =
				($error !== null)
					? "{$error->getCode()}: {$error->getMessage()}"
					: 'Input validation failed. Verify arguments against the tool input schema.'
			;

			throw new McpException($message);
		}
	}
}
