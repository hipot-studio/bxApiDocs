<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetAiSiteChangeStatusDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\GetAiSiteChangeStatusSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\GetAiSiteChangeStatusHandler;
use Bitrix\Main\Validation\ValidationService;
use Throwable;

class GetAiSiteChangeStatusTool extends BaseTool
{
	use AiSiteRuntimeAvailabilityToolGate;

	public const ACTION_NAME = 'get_ai_site_change_status';

	public function __construct(
		private readonly GetAiSiteChangeStatusHandler $handler,
		private readonly GetAiSiteChangeStatusSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Optionally waits for the requested number of seconds and then returns the current AI page-change generation status with final siteId and pageId when the job is completed.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = GetAiSiteChangeStatusDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);
		$startedAt = microtime(true);

		try
		{
			$this->validate($dto);

			$response = $this->runInUserContext(
				$effectiveUserId,
				fn() => $this->handler->handle($dto, $effectiveUserId),
			);
			$this->logExecution($effectiveUserId, $startedAt, $dto->jobId, $response, null);

			return $response;
		}
		catch (Throwable $e)
		{
			$this->logExecution($effectiveUserId, $startedAt, $dto->jobId, null, $e);

			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}

	private function logExecution(
		int $userId,
		float $startedAt,
		string $requestedJobId,
		?array $response,
		?Throwable $error,
	): void
	{
		$status = (string)($response['status'] ?? 'error');
		$jobId = (string)($response['jobId'] ?? $requestedJobId);
		$pageId = (int)($response['pageId'] ?? 0);
		$durationMs = (int)round((microtime(true) - $startedAt) * 1000);

		$context = [
			'toolName' => self::ACTION_NAME,
			'jobId' => $jobId,
			'pageId' => $pageId,
			'userId' => $userId,
			'durationMs' => $durationMs,
			'status' => $status,
		];
		if ($error)
		{
			$context['errorMessage'] = $error->getMessage();
		}

		$this->tracedLogger->info('Tool {toolName} finished with status {status}', $context);
	}
}
