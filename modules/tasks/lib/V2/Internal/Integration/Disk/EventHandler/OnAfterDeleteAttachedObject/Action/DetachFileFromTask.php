<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\EventHandler\OnAfterDeleteAttachedObject\Action;

use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\FileBbCodeService;
use Bitrix\Tasks\V2\Internal\Repository\Compatibility\TaskRepository;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;
use Throwable;

class DetachFileFromTask
{
	use DetachFileFromUserFieldTrait;

	public function __construct(
		private readonly ?TaskRepository $compatibilityRepository = null,
		private readonly ?FileBbCodeService $fileBbCodeService = null,
		private readonly ?UpdateTaskService $updateService = null,
	)
	{
	}

	public function __invoke(int $taskId, int $attachId, int $objectId): void
	{
		$this->detachFileFromUserField(UserField::TASK, $taskId, $attachId);
		$this->detachFileFromDescription($taskId, $attachId, $objectId);
	}

	private function detachFileFromDescription(int $taskId, int $attachId, int $objectId): void
	{
		try
		{
			$container = Container::getInstance();
			$compatibilityRepository = $this->compatibilityRepository ?? $container->getTaskCompatabilityRepository();
			$fileBbCodeService = $this->fileBbCodeService ?? $container->get(FileBbCodeService::class);
			$updateService = $this->updateService ?? $container->getUpdateTaskService();

			$fullData = $compatibilityRepository->getTaskData($taskId);

			$cleanedDescription = $this->stripDetachedFileFromDescription(
				$fileBbCodeService,
				(string)($fullData['DESCRIPTION'] ?? ''),
				$attachId,
				$objectId,
			);
			if ($cleanedDescription === null)
			{
				return;
			}

			$changedBy = (int)($fullData['CHANGED_BY'] ?? 0);
			$changedDate = $fullData['CHANGED_DATE'] ?? null;
			$changedTs = is_string($changedDate) ? (int)MakeTimeStamp($changedDate) : 0;

			$task = new Entity\Task(
				id: $taskId,
				description: $cleanedDescription,
				changedTs: $changedTs > 0 ? $changedTs : null,
				changedBy: $changedBy > 0 ? new Entity\User(id: $changedBy) : null,
			);

			$config = new UpdateConfig(
				userId: $changedBy,
				skipNotifications: true,
				skipRecount: true,
				skipComments: true,
				skipPush: true,
				skipBP: true,
			);

			$updateService->update($task, $config);
		}
		catch (TaskNotExistsException)
		{
			return;
		}
		catch (Throwable $exception)
		{
			LogFacade::logThrowable($exception);
		}
	}
}
