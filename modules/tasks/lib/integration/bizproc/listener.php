<?php

namespace Bitrix\Tasks\Integration\Bizproc;

use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Dto\DocumentDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Tasks\Integration\Bizproc\Document\Task;
use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;
use Bitrix\Tasks\Internals\Task\EO_Member_Collection;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Util\Restriction\Bitrix24Restriction\Limit\TaskLimit;

/**
 * @method static onTaskAdd($id, array $fields)
 * @method static onTaskUpdate($id, array $fields, array $previousFields)
 * @method static onPlanTaskStageUpdate($memberId, $taskId, $stageId)
 * @method static onTaskDeleteExecute($id)
 * @method static onTaskExpired($id, array $fields)
 * @method static onTaskExpiredSoon($id, array $fields)
 * @method static onTaskFieldChanged($id, array $fields, array $previousFields)
 */

class Listener
{
	public const EVENT_TASK_EXPIRED = EventDictionary::EVENT_TASK_EXPIRED;
	public const EVENT_TASK_EXPIRED_SOON = EventDictionary::EVENT_TASK_EXPIRED_SOON;

	private const USE_BACKGROUND_KEY = 'tasks_bizproc_background';

	public function __construct()
	{

	}

	/**
	 * @param string $name
	 * @param array $args
	 * @return false|mixed|void
	 */
	public static function __callStatic(string $name, array $args = [])
	{
		$listener = new self();

		$methodName = $name.'Execute';

		if (!is_callable([$listener, $methodName]))
		{
			return false;
		}

		if (!$listener->useBackground())
		{
			return call_user_func_array([$listener, $methodName], $args);
		}

		$application = Application::getInstance();
		$application && $application->addBackgroundJob(
			[$listener, $methodName],
			$args
		);
	}

	public function onTaskAddExecute($id, array $fields)
	{
		if (TaskLimit::isLimitExceeded() || !$this->loadBizproc())
		{
			return false;
		}

		//fix meta statuses
		if (!empty($fields['REAL_STATUS']))
		{
			$fields['STATUS'] = $fields['REAL_STATUS'];
		}

		//fix creation from template
		if (empty($fields['STATUS']))
		{
			$fields['STATUS'] = Status::PENDING;
		}

		//Run project automation
		if (isset($fields['GROUP_ID']) && $fields['GROUP_ID'] > 0)
		{
			$projectDocumentType = $this->resolveProjectTaskType($fields['GROUP_ID']);
			$this->runOnAdd((int)$id, $projectDocumentType, $fields);
		}

		//Run plan & personal automation

		$members = $this->extractTaskMembers($fields);

		foreach ($members as $memberId)
		{
			$planDocumentType = Document\Task::resolvePlanTaskType($memberId);
			$this->runOnAdd((int)$id, $planDocumentType, $fields);

			$personalDocumentType = Document\Task::resolvePersonalTaskType($memberId);
			$this->runOnAdd((int)$id, $personalDocumentType, $fields);
		}
	}

	public function onTaskUpdateExecute($id, array $fields, array $previousFields)
	{
		if (TaskLimit::isLimitExceeded() || !$this->loadBizproc())
		{
			return false;
		}

		$projectId = $fields['GROUP_ID'] ?? $previousFields['GROUP_ID'];
		$statusChanged = (isset($fields['STATUS']) && (string)$fields['STATUS'] !== (string)$previousFields['STATUS']);
		$changedFields = $this->compareFields($fields, $previousFields);

		//Stop automation on previous project if project was changed
		if (
			isset($fields['GROUP_ID'])
			&& (int)$fields['GROUP_ID'] !== (int)$previousFields['GROUP_ID']
			&& $previousFields['GROUP_ID'] > 0
		)
		{
			$projectTaskType = $this->resolveProjectTaskType($previousFields['GROUP_ID']);
			Automation\Factory::stopAutomation($projectTaskType, $id);
		}

		if ($projectId)
		{
			$projectDocumentType = $this->resolveProjectTaskType((int)$projectId);
			$this->runOnUpdateProjectTask((int)$id, $projectDocumentType, $fields, $changedFields);
		}

		//Run plan & personal automation
		$membersDiff = $this->getMembersDiff($fields, $previousFields);

		//Stop automation for users who left the task
		foreach ($membersDiff->minus as $memberId)
		{
			$planDocumentType = Document\Task::resolvePlanTaskType($memberId);
			Automation\Factory::stopAutomation($planDocumentType, $id);

			$personalDocumentType = Document\Task::resolvePersonalTaskType($memberId);
			Automation\Factory::stopAutomation($personalDocumentType, $id);
		}

		foreach ($membersDiff->plus as $memberId)
		{
			$this->runOnAddMemberToTask((int)$id, $memberId, $fields, $changedFields);
		}

		if ($changedFields || $statusChanged)
		{
			foreach ($membersDiff->current as $memberId)
			{
				$this->runOnUpdatePersonalTask((int)$id, $memberId, $fields, $changedFields);
			}
		}
	}

	public function onPlanTaskStageUpdateExecute($memberId, $taskId, $stageId)
	{
		if (TaskLimit::isLimitExceeded() || !$this->loadBizproc())
		{
			return false;
		}

		$planDocumentType = Document\Task::resolvePlanTaskType($memberId);
		$starter = $this->getStarterOnUpdate($taskId, $planDocumentType, ['STATUS']);
		if ($starter)
		{
			$starter->start();
		}
		else
		{
			Automation\Factory::runOnStatusChanged($planDocumentType, $taskId);
		}
	}

	public function onTaskDeleteExecute($id)
	{
		if (!$this->loadBizproc())
		{
			return false;
		}

		$errors = [];
		$documentId = Document\Task::resolveDocumentId($id);
		\CBPDocument::OnDocumentDelete($documentId, $errors);

		return true;
	}

	public function onTaskExpiredExecute($id, array $fields)
	{
		if (TaskLimit::isLimitExceeded() || !$this->loadBizproc())
		{
			return false;
		}

		$documentId = Document\Task::resolveDocumentId($id);
		$starter = $this->getStarterOnEvent();

		//Run project trigger
		if ($fields['GROUP_ID'] > 0)
		{
			$projectDocumentType = $this->resolveProjectTaskType($fields['GROUP_ID']);
			if ($starter)
			{
				$starter->addEvent(
					Automation\Trigger\Expired::getCode(),
					[new DocumentDto($documentId, $this->getComplexDocumentTypeByTaskId($id, $projectDocumentType))],
					$fields
				);
			}
			else
			{
				Automation\Trigger\Expired::execute($projectDocumentType, $id, $fields);
			}
		}

		//Run plan trigger
		$members = $this->extractTaskMembers($fields);
		foreach ($members as $memberId)
		{
			$planDocumentType = Document\Task::resolvePlanTaskType($memberId);
			if ($starter)
			{
				$starter->addEvent(
					Automation\Trigger\Expired::getCode(),
					[new DocumentDto($documentId, $this->getComplexDocumentTypeByTaskId($id, $planDocumentType))],
					$fields
				);
			}
			else
			{
				Automation\Trigger\Expired::execute($planDocumentType, $id, $fields);
			}
		}

		if ($starter)
		{
			$document = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexId($id);
			$documentType = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();
			$starter->addEvent(
				'TasksExpiredTrigger',
				[new DocumentDto($document, $documentType)],
				$fields
			);
		}

		$starter?->start();
	}

	public function onTaskExpiredSoonExecute($id, array $fields)
	{
		if (TaskLimit::isLimitExceeded() || !$this->loadBizproc())
		{
			return false;
		}

		$documentId = Document\Task::resolveDocumentId($id);
		$starter = $this->getStarterOnEvent();

		//Run project trigger
		if ($fields['GROUP_ID'] > 0)
		{
			$projectDocumentType = $this->resolveProjectTaskType($fields['GROUP_ID']);

			if ($starter)
			{
				$starter->addEvent(
					Automation\Trigger\ExpiredSoon::getCode(),
					[new DocumentDto($documentId, $this->getComplexDocumentTypeByTaskId($id, $projectDocumentType))],
					$fields
				);
			}
			else
			{
				Automation\Trigger\ExpiredSoon::execute($projectDocumentType, $id, $fields);
			}
		}

		//Run plan trigger
		$members = $this->extractTaskMembers($fields);
		foreach ($members as $memberId)
		{
			$planDocumentType = Document\Task::resolvePlanTaskType($memberId);
			if ($starter)
			{
				$starter->addEvent(
					Automation\Trigger\ExpiredSoon::getCode(),
					[new DocumentDto($documentId, $this->getComplexDocumentTypeByTaskId($id, $planDocumentType))],
					$fields
				);
			}
			else
			{
				Automation\Trigger\ExpiredSoon::execute($planDocumentType, $id, $fields);
			}
		}

		$starter?->start();
	}

	public function onTaskFieldChangedExecute($id, array $fields, array $previousFields): Main\Result
	{
		$result = new Main\Result();

		if (!$this->loadBizproc())
		{
			return $result->addError(new Main\Error('Unable to load bizproc module'));
		}

		if (TaskLimit::isLimitExceeded())
		{
			return (
				$result
					->addError(new Main\Error(
						Main\Localization\Loc::getMessage('TASKS_BP_LISTENER_RESUME_RESTRICTED')
					))
			);
		}

		$projectId = $fields['GROUP_ID'] ?? $previousFields['GROUP_ID'];
		$changedFields = $this->compareFields($fields, $previousFields);

		$documentId = Document\Task::resolveDocumentId($id);
		$starter = $this->getStarterOnEvent();

		//Run project trigger
		if ($projectId > 0)
		{
			$documentType = $this->resolveProjectTaskType($projectId);

			if ($starter)
			{
				$starter->addEvent(
					Automation\Trigger\TasksFieldChangedTrigger::getCode(),
					[new DocumentDto($documentId, $this->getComplexDocumentTypeByTaskId($id, $documentType))],
					['CHANGED_FIELDS' => $changedFields]
				);
			}
			else
			{
				Automation\Trigger\TasksFieldChangedTrigger::execute($documentType, $id, ['CHANGED_FIELDS' => $changedFields]);
			}
		}

		//Run plan trigger
		$members = $this->extractTaskMembers(array_merge($previousFields, $fields));
		foreach ($members as $memberId)
		{
			$planDocumentType = Document\Task::resolvePlanTaskType($memberId);
			if ($starter)
			{
				$starter->addEvent(
					Automation\Trigger\TasksFieldChangedTrigger::getCode(),
					[new DocumentDto($documentId, $this->getComplexDocumentTypeByTaskId($id, $planDocumentType))],
					['CHANGED_FIELDS' => $changedFields]
				);
			}
			else
			{
				Automation\Trigger\TasksFieldChangedTrigger::execute($planDocumentType, $id, ['CHANGED_FIELDS' => $changedFields]);
			}
		}

		$starter?->start();

		return $result;
	}

	/**
	 * @return bool
	 */
	private function useBackground(): bool
	{
		if (Main\Config\Option::get('tasks', self::USE_BACKGROUND_KEY, 'null') !== 'null')
		{
			return true;
		}

		return false;
	}

	private function fireStatusTrigger($documentType, $taskId, $fields): bool
	{
		$result = Automation\Trigger\Status::execute($documentType, $taskId, $fields);
		if ($result->isSuccess())
		{
			$data = $result->getData();
			if (!empty($data['triggerApplied']))
			{
				return true;
			}
		}

		return false;
	}

	private function fireFieldChangedTrigger($documentType, $taskId, $fields): bool
	{
		$result = Automation\Trigger\TasksFieldChangedTrigger::execute(
			$documentType,
			$taskId,
			['CHANGED_FIELDS' => $fields]
		);
		if ($result->isSuccess())
		{
			$data = $result->getData();
			if (!empty($data['triggerApplied']))
			{
				return true;
			}
		}

		return false;
	}

	private function resolveProjectTaskType($projectId): string
	{
		$documentType = Document\Task::resolveProjectTaskType($projectId);
		if ($projectId && Main\Loader::includeModule('socialnetwork'))
		{
			$group = Workgroup::getById($projectId);
			if ($group && $group->isScrumProject())
			{
				$documentType = Document\Task::resolveScrumProjectTaskType($projectId);
			}
		}

		return $documentType;
	}

	private function loadBizproc()
	{
		return Main\Loader::includeModule('bizproc');
	}

	private function extractTaskMembers(array $fields)
	{
		$users = [];

		if (!empty($fields['CREATED_BY']))
		{
			$users[] = $fields['CREATED_BY'];
		}

		if (!empty($fields['RESPONSIBLE_ID']))
		{
			$users[] = $fields['RESPONSIBLE_ID'];
		}

		if (!empty($fields['ACCOMPLICES']))
		{
			if (is_object($fields['ACCOMPLICES']))
			{
				$fields['ACCOMPLICES'] = $fields['ACCOMPLICES']->toArray();
			}

			$users = array_merge($users, $fields['ACCOMPLICES']);
		}

		if (!empty($fields['AUDITORS']))
		{
			if (is_object($fields['AUDITORS']))
			{
				$fields['AUDITORS'] = $fields['AUDITORS']->toArray();
			}

			$users = array_merge($users, $fields['AUDITORS']);
		}

		if (is_object($fields['MEMBER_LIST'] ?? null))
		{
			/** @var $members EO_Member_Collection */
			$members = $fields['MEMBER_LIST'];
			$users = array_merge($users, $members->getUserIdList());
		}

		return array_map('intval', array_unique($users));
	}

	private function getMembersDiff(array $fields, array $previousFields)
	{
		$previousMembers = $this->extractTaskMembers($previousFields);
		$currentMembers = $this->extractTaskMembers(array_merge($previousFields, $fields));

		$plus = array_diff($currentMembers, $previousMembers);
		$minus = array_diff($previousMembers, $currentMembers);

		return (object)['plus' => $plus, 'minus' => $minus, 'current' => $currentMembers];
	}

	private function compareFields(array $actual, array $previous): array
	{
		$diff = [];
		foreach ($actual as $key => $field)
		{
			if (!array_key_exists($key, $previous) || $previous[$key] != $field)
			{
				$diff[] = $key;
			}
		}

		return $diff;
	}

	private function runOnAdd(int $taskId, string $documentType, array $fields): void
	{
		$starter = $this->getStarterOnAdd($taskId, $documentType);
		if ($starter)
		{
			$starter->start();
		}
		else
		{
			//run automation
			Automation\Factory::runOnAdd($documentType, $taskId, $fields);
		}
	}

	private function getStarterOnAdd(int $id, string $taskType): ?Starter
	{
		if ($id > 0 && $this->isStarterEnabled())
		{
			$documentId = Task::resolveDocumentId($id);
			$documentType = $this->getComplexDocumentTypeByTaskId($id, $taskType);

			return (
				Starter::getByScenario(Scenario::onDocumentAdd)
					->setDocument(new DocumentDto($documentId, $documentType))
					->setContext(new ContextDto('tasks'))
			);
		}

		return null;
	}

	private function runOnUpdateProjectTask(int $id, string $documentType, array $fields, array $changedFields): void
	{
		$documentId = Document\Task::resolveDocumentId($id);

		if (!in_array('STAGE_ID', $changedFields, true) && (isset($fields['STAGE_ID']) && (int)$fields['STAGE_ID'] === 0))
		{
			$changedFields[] = 'STAGE_ID';
		}

		$starter = $this->getStarterOnUpdate($id, $documentType, $changedFields);

		$isTriggerApplied = false;

		if (in_array('STATUS', $changedFields, true))
		{
			if ($starter)
			{
				$starter->addEvent(
					Automation\Trigger\Status::getCode(),
					[new DocumentDto($documentId, $this->getComplexDocumentTypeByTaskId($id, $documentType))],
					$fields
				);
			}
			else
			{
				$isTriggerApplied = $this->fireStatusTrigger($documentType, $id, $fields);
			}
		}

		if ($changedFields)
		{
			if ($starter)
			{
				$starter->addEvent(
					Automation\Trigger\TasksFieldChangedTrigger::getCode(),
					[new DocumentDto($documentId, $this->getComplexDocumentTypeByTaskId($id, $documentType))],
					['CHANGED_FIELDS' => $changedFields]
				);
			}
			elseif (!$isTriggerApplied)
			{
				$isTriggerApplied = $this->fireFieldChangedTrigger($documentType, $id, $changedFields);
			}
		}

		if (in_array('STAGE_ID', $changedFields, true) && !$starter && !$isTriggerApplied)
		{
			Automation\Factory::runOnStatusChanged($documentType, $id, $fields);
		}

		$starter?->start();
	}

	private function runOnAddMemberToTask(int $id, int $memberId, array $fields, array $changedFields): void
	{
		$documentId = Document\Task::resolveDocumentId($id);

		$planDocumentType = Document\Task::resolvePlanTaskType($memberId);

		$starter = $this->getStarterOnAdd($id, $planDocumentType);

		$isTriggerApplied = false;

		if (in_array('STATUS', $changedFields, true))
		{
			if ($starter)
			{
				$starter->addEvent(
					Automation\Trigger\Status::getCode(),
					[new DocumentDto($documentId, $this->getComplexDocumentTypeByTaskId($id, $planDocumentType))],
					$fields
				);
			}
			else
			{
				$isTriggerApplied = $this->fireStatusTrigger($planDocumentType, $id, $fields);
			}
		}

		if ($changedFields)
		{
			if ($starter)
			{
				$starter->addEvent(
					Automation\Trigger\TasksFieldChangedTrigger::getCode(),
					[new DocumentDto($documentId, $this->getComplexDocumentTypeByTaskId($id, $planDocumentType))],
					['CHANGED_FIELDS' => $changedFields]
				);
			}
			elseif (!$isTriggerApplied)
			{
				$isTriggerApplied = $this->fireFieldChangedTrigger($planDocumentType, $id, $changedFields);
			}
		}

		if ($starter)
		{
			$starter->start();
		}
		elseif (!$isTriggerApplied)
		{
			Automation\Factory::runOnAdd($planDocumentType, $id, $fields);
		}

		//Run personal
		$personalDocumentType = Document\Task::resolvePersonalTaskType($memberId);
		$this->runOnAdd($id, $personalDocumentType, $fields);
	}

	private function runOnUpdatePersonalTask(int $id, int $memberId, array $fields, array $changedFields): void
	{
		$documentId = Document\Task::resolveDocumentId($id);

		$planDocumentType = Document\Task::resolvePlanTaskType($memberId);

		$starter = $this->getStarterOnEvent();

		if (in_array('STATUS', $changedFields, true))
		{
			if ($starter)
			{
				$starter->addEvent(
					Automation\Trigger\Status::getCode(),
					[new DocumentDto($documentId, $this->getComplexDocumentTypeByTaskId($id, $planDocumentType))],
					$fields
				);
			}
			else
			{
				$this->fireStatusTrigger($planDocumentType, $id, $fields);
			}
		}

		if ($changedFields)
		{
			if ($starter)
			{
				$starter->addEvent(
					Automation\Trigger\TasksFieldChangedTrigger::getCode(),
					[new DocumentDto($documentId, $this->getComplexDocumentTypeByTaskId($id, $planDocumentType))],
					['CHANGED_FIELDS' => $changedFields]
				);
			}
			else
			{
				$this->fireFieldChangedTrigger($planDocumentType, $id, $changedFields);
			}
		}

		$starter?->start();

		if (in_array('STATUS', $changedFields, true))
		{
			$personalDocumentType = Document\Task::resolvePersonalTaskType($memberId);
			$starter = $this->getStarterOnUpdate($id, $personalDocumentType, $changedFields);
			if ($starter)
			{
				$starter->start();
			}
			else
			{
				Automation\Factory::runOnStatusChanged($personalDocumentType, $id, $fields);
			}
		}
	}

	private function getStarterOnUpdate(int $id, string $taskType, array $changedFieldNames): ?Starter
	{
		if ($id > 0 && $this->isStarterEnabled())
		{
			$documentId = Task::resolveDocumentId($id);
			$documentType = $this->getComplexDocumentTypeByTaskId($id, $taskType);

			return (
				Starter::getByScenario(Scenario::onDocumentUpdate)
					->setDocument(new DocumentDto($documentId, $documentType, $changedFieldNames))
					->setContext(new ContextDto('tasks'))
			);
		}

		return null;
	}

	private function getStarterOnEvent(): ?Starter
	{
		if ($this->isStarterEnabled())
		{
			return (
				Starter::getByScenario(Scenario::onEvent)
					->setContext(new ContextDto('tasks'))
			);
		}

		return null;
	}

	private function isStarterEnabled(): bool
	{
		return $this->loadBizproc() && class_exists(Starter::class) && Starter::isEnabled();
	}

	private function getComplexDocumentTypeByTaskId(int $id, string $documentType): array
	{
		[$moduleId, $entity, $documentId] = Document\Task::resolveDocumentId($id);

		return [$moduleId, $entity, $documentType];
	}
}
