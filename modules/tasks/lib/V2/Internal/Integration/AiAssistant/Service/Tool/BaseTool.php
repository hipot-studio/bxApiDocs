<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Loader;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\BaseSchemaBuilder;

Loader::requireModule('aiassistant');

abstract class BaseTool extends ToolContract
{
	public const ACTION_NAME = '';

	public function __construct(
		private readonly BaseSchemaBuilder $schemaBuilder,
		private readonly ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function getName(): string
	{
		return static::ACTION_NAME;
	}

	public function getInputSchema(): array
	{
		return $this->schemaBuilder->build(static::ACTION_NAME);
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

	protected function createFailureResponse(string $message): string
	{
		return "Failed to execute the tool '{$this->getName()}': {$message}";
	}
}
