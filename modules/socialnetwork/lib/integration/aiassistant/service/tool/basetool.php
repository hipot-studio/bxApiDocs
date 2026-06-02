<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Integration\AiAssistant\Service\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Loader;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Socialnetwork\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Socialnetwork\Integration\AiAssistant\Exception\ToolException;
use Throwable;

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
	 * @throws DtoValidationException
	 */
	protected function validate(object $dto): void
	{
		$validationResult = $this->validationService->validate($dto);

		if (!$validationResult->isSuccess())
		{
			$error = $validationResult->getError();

			throw new DtoValidationException("{$error->getCode()}: {$error->getMessage()}");
		}
	}

	protected function createToolException(string $message, ?Throwable $previous = null): ToolException
	{
		return new ToolException(
			action: $this->getName(),
			message: $message,
			previous: $previous,
		);
	}
}
