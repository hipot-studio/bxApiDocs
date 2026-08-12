<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\ChangeAiSiteDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\ChangeAiSiteSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\ChangeAiSiteHandler;
use Bitrix\Landing\Rights;
use Bitrix\Main\Validation\ValidationService;
use Throwable;

class ChangeAiSiteTool extends BaseTool
{
	use AiSiteRuntimeAvailabilityToolGate;

	public const ACTION_NAME = 'change_ai_site';

	public function __construct(
		private readonly ChangeAiSiteHandler $handler,
		private readonly ChangeAiSiteSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Starts AI page-change generation and returns either status=running with a jobId or status=completed with final siteId and pageId.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = ChangeAiSiteDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);
		$startedAt = microtime(true);

		try
		{
			$this->validate($dto);

			$response = $this->runInUserContext($effectiveUserId, function() use ($dto, $effectiveUserId)
			{
				$this->assertPermission(
					$this->canEditPage($dto->pageId),
					'Access denied. You do not have permission to edit this page.',
				);

				return $this->handler->handle($dto, $effectiveUserId);
			});
			$this->logExecution($effectiveUserId, $startedAt, $response, $dto->pageId, null);

			return $response;
		}
		catch (Throwable $e)
		{
			$this->logExecution($effectiveUserId, $startedAt, null, $dto->pageId, $e);

			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}

	protected function canEditPage(int $pageId): bool
	{
		return Rights::hasAccessForLanding($pageId, Rights::ACCESS_TYPES['edit']);
	}

	private function logExecution(
		int $userId,
		float $startedAt,
		?array $response,
		int $requestedPageId,
		?Throwable $error,
	): void
	{
		$status = (string)($response['status'] ?? 'error');
		$jobId = (string)($response['jobId'] ?? '');
		$pageId = (int)($response['pageId'] ?? $requestedPageId);
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
