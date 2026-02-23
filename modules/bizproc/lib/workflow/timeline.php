<?php

namespace Bitrix\Bizproc\Workflow;

use Bitrix\Bizproc\Api\Service\WorkflowStateService;
use Bitrix\Bizproc\Public\Service\Task\UnArchiveTaskService;
use Bitrix\Bizproc\UI\Helpers\DurationFormatter;
use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;
use Bitrix\Bizproc\Workflow\Entity\WorkflowMetadataTable;
use Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable;
use Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTable;
use Bitrix\Bizproc\Workflow\Task\TimelineTask;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Bizproc\Api\Request\WorkflowStateService\GetExecutionTimeRequest;

class Timeline implements \JsonSerializable
{
	private ?int $userId = null;
	private WorkflowState $workflow;

	public static function createByWorkflowId(string $workflowId): ?static
	{
		$workflow = WorkflowStateTable::query()
			->setSelect([
				'ID',
				'MODULE_ID',
				'ENTITY',
				'DOCUMENT_ID',
				'MODIFIED',
				'STARTED_BY',
				'STARTED',
				'STATE',
				'WORKFLOW_TEMPLATE_ID',
				'TASKS.ID',
				'TASKS.WORKFLOW_ID',
				'TASKS.ACTIVITY',
				'TASKS.ACTIVITY_NAME',
				'TASKS.NAME',
				'TASKS.STATUS',
				'TASKS.CREATED_DATE',
				'TASKS.MODIFIED',
				'TASKS.PARAMETERS',
				'TASKS.TASK_USERS.USER_ID',
				'TASKS.TASK_USERS.STATUS',
				'TASKS.TASK_USERS.DATE_UPDATE',
				'TASKS_ARCHIVE.TASKS_DATA',
			])
			->setOrder(['TASKS.ID' => 'ASC'])
			->setFilter(['=ID' => $workflowId])
			->exec()
			->fetchObject()
		;

		return $workflow ? new static($workflow) : null;
	}

	public function __construct(WorkflowState $workflow)
	{
		$this->workflow = $workflow;
	}

	public function setUserId(int $userId): static
	{
		$this->userId = $userId;

		return $this;
	}

	public function getWorkflowState(): WorkflowState
	{
		return $this->workflow;
	}

	public function getExecutionTime(): ?int
	{
		$workflowStateService = new WorkflowStateService();
		return $workflowStateService->getExecutionTime(
			new GetExecutionTimeRequest(
				workflowId: $this->workflow->getId(),
				workflowStarted: $this->workflow->getStarted(),
				workflowModified: $this->workflow->getModified(),
			)
		)->getRoundedExecutionTime();
	}

	public function getTimeToStart(): ?int
	{
		$metadata = WorkflowMetadataTable::query()
			->setSelect(['START_DURATION'])
			->setFilter(['=WORKFLOW_ID' => $this->workflow->getId()])
			->setLimit(1)
			->exec()
			->fetchObject()
		;

		$startDuration = $metadata?->getStartDuration();
		if ($startDuration)
		{
			return DurationFormatter::roundTimeInSeconds($startDuration, 2);
		}

		return null;
	}

	/**
	 * @return TimelineTask[]
	 */
	public function getTasks(): array
	{
		$archiveTasks = $this->getTasksFromArchive();
		$tasks = $archiveTasks ?: $this->workflow->getTasks();

		$timelineTasks = [];
		foreach ($tasks as $task)
		{
			$timelineTask = new TimelineTask($task);
			if (isset($this->userId))
			{
				$timelineTask->setUserId($this->userId);
			}

			$timelineTasks[] = $timelineTask;
		}

		return $timelineTasks;
	}

	private function getTasksFromArchive(): array
	{
		$tasks = [];
		$archives =
			TaskArchiveTable::query()
				->setSelect(['ID', 'TASKS_DATA'])
				->where('WORKFLOW_ID', $this->workflow->getId())
				->fetchAll()
		;
		$archives = array_column($archives, 'TASKS_DATA', 'ID');
		if ($archives)
		{
			$unArchiveTaskService = new UnArchiveTaskService($archives);
			$tasksData = $unArchiveTaskService->getTasks(sort: ['ID' => SORT_ASC]);

			foreach ($tasksData as $task)
			{
				$tasks[] = Task::createFromArchive($task);
			}
		}

		return $tasks;
	}

	public function jsonSerialize(): array
	{
		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$complexDocumentId = $this->workflow->getComplexDocumentId();
		try
		{
			$complexDocumentType = $documentService->getDocumentType($complexDocumentId);
		}
		catch (SystemException | \Exception $exception)
		{
			$complexDocumentType = null;
		}

		$entityName =
			isset($complexDocumentType)
				? $documentService->getDocumentTypeCaption($complexDocumentType)
				: null
		;

		$documentUrl = $documentService->getDocumentDetailUrl($complexDocumentId);

		return [
			'documentId' => $complexDocumentId,
			'documentType' => $complexDocumentType,
			'moduleName' => $this->getModuleName($complexDocumentId[0] ?? ''),
			'entityName' => $entityName ?? '',
			'documentUrl' => empty($documentUrl) ? null : $documentUrl,
			'documentName' => $documentService->getDocumentName($complexDocumentId) ?? '',
			'isWorkflowRunning' => $this->isWorkflowRunning(),
			'timeToStart' => $this->getTimeToStart(),
			'executionTime' => $this->getExecutionTime(),
			'workflowModifiedDate' => $this->workflow->getModified()->getTimestamp(),
			'started' => $this->workflow->getStarted()?->getTimestamp(),
			'startedBy' => $this->workflow->getStartedBy(),
			'tasks' => $this->getTasks(),
		];
	}

	private function isWorkflowRunning(): bool
	{
		return WorkflowInstanceTable::exists($this->workflow->getId());
	}

	private function getModuleName(string $moduleId): string
	{
		return match ($moduleId) {
			'crm' => 'CRM',
			'disk' => Loc::getMessage('BIZPROC_TIMELINE_DISK_MODULE_NAME'),
			'lists' => Loc::getMessage('BIZPROC_TIMELINE_LISTS_MODULE_NAME'),
			'rpa' => 'RPA',
			default => '',
		};
	}
}
