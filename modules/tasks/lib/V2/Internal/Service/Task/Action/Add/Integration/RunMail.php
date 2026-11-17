<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Integration;

use Bitrix\Mail\Integration\Tasks\TaskMailSourceService;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\LoggerInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;
use Throwable;

class RunMail
{
	public function __construct(
		private readonly AddConfig $config,
		private readonly LoggerInterface $logger,
	)
	{
	}

	public function __invoke(int $taskId, ?Task\Source $source = null): void
	{
		$sourceData = $source?->data ?? [];
		$sourceId = (int)($sourceData['entityId'] ?? 0);
		if (
			$taskId <= 0
			|| $sourceId <= 0
			|| !Loader::includeModule('mail')
			|| !class_exists(TaskMailSourceService::class)
		)
		{
			return;
		}

		$sourceType = $this->getMailSourceType($source?->type);
		if ($sourceType === null)
		{
			return;
		}

		$userId = $this->config->getUserId();
		if (!TaskMailSourceService::canReadSource($sourceType, $sourceId, $userId))
		{
			return;
		}

		if ($sourceType === TaskMailSourceService::SOURCE_TYPE_MAIL_MESSAGE)
		{
			$accessResult = TaskMailSourceService::grantMailMessageAccess($taskId, $sourceId, $userId);
			if (!$accessResult->isSuccess())
			{
				$this->logger->logWarning(
					[
						'taskId' => $taskId,
						'sourceType' => $sourceType,
						'sourceId' => $sourceId,
						'errors' => $accessResult->getErrorMessages(),
					],
					'TASKS_MAIL_SOURCE_ACCESS_GRANT_FAILED',
				);

				return;
			}
		}

		// The source table may still be missing when the mail update has not applied its DDL yet: the
		// storage failure is logged, the task itself must survive it.
		try
		{
			$saveResult = TaskMailSourceService::saveSource($taskId, $sourceType, $sourceId);
			$saveErrors = $saveResult->isSuccess() ? [] : $saveResult->getErrorMessages();
		}
		catch (Throwable $exception)
		{
			$saveErrors = [$exception->getMessage()];
		}

		if ($saveErrors !== [])
		{
			$this->logger->logWarning(
				[
					'taskId' => $taskId,
					'sourceType' => $sourceType,
					'sourceId' => $sourceId,
					'errors' => $saveErrors,
				],
				'TASKS_MAIL_SOURCE_SAVE_FAILED',
			);

			return;
		}

		// Best-effort mark for the mail client and the search: the message behind the CRM activity now
		// has a task. It grants no access to the email: a task viewer opens it by a task-scoped token.
		if ($sourceType === TaskMailSourceService::SOURCE_TYPE_CRM_ACTIVITY)
		{
			TaskMailSourceService::bindCrmActivityMailMessageToTask($taskId, $sourceId, $userId);
		}
	}

	private function getMailSourceType(?Task\SourceType $sourceType): ?string
	{
		return match ($sourceType)
		{
			Task\SourceType::MAIL_MESSAGE => TaskMailSourceService::SOURCE_TYPE_MAIL_MESSAGE,
			Task\SourceType::CRM_ACTIVITY => TaskMailSourceService::SOURCE_TYPE_CRM_ACTIVITY,
			default => null,
		};
	}
}
