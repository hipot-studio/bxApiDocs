<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Tasks;
use Bitrix\Tasks\Integration\BizProc\NodeFilter\TargetDocumentAccessGuard;
use Bitrix\Tasks\Integration\BizProc\NodeFilter\TargetDocumentResolverTrait;

$runtime = CBPRuntime::GetRuntime();
$runtime->IncludeActivityFile('SetFieldActivity');

\Bitrix\Main\Loader::includeModule('tasks');

class CBPTasksUpdateTaskActivity extends CBPSetFieldActivity
{
	use TargetDocumentResolverTrait;

	/**
	 * Every field this feature opened for writing. Without a subject none of them may be written,
	 * while a set that was already writable before the feature keeps the master behaviour.
	 */
	private const FIELDS_OPENED_BY_FEATURE = [
		'GROUP_ID',
		'PARENT_ID',
		'DEPENDS_ON',
		'UF_CRM_TASK',
		'RESPONSIBLE_ID',
		'DURATION_PLAN',
		'TIME_ESTIMATE',
	];

	/**
	 * Subset of the opened fields the task access model is built from: only they raise the gate
	 * to ACTION_TASK_SAVE. Any other set keeps the legacy ACTION_TASK_EDIT gate.
	 */
	private const ACCESS_MODEL_FIELDS = [
		'GROUP_ID',
		'PARENT_ID',
		'RESPONSIBLE_ID',
	];

	public function Execute()
	{
		if (!CModule::IncludeModule('tasks'))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$fieldValue = $this->FieldValue;

		if (!is_array($fieldValue) || count($fieldValue) <= 0)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$documentId = $this->resolveTargetDocumentId();
		$documentType = $this->resolveTargetDocumentType($documentId);
		$taskId = $documentId[2];

		if (method_exists($this, 'prepareFieldsValues'))
		{
			$fieldValue = $this->prepareFieldsValues($documentId, $documentType, $fieldValue);
		}

		$newTask = $this->affectsAccessModel($fieldValue)
			? $this->createUpdatedTaskModel($taskId, $fieldValue)
			: null
		;
		$canUpdate = $this->canUpdateResolvedTaskTarget($documentId, $fieldValue, $newTask);
		if ($canUpdate === null)
		{
			$canUpdate = $this->canUpdateRootTaskTarget($taskId, $documentType[2], $fieldValue, $newTask);
		}

		if (!$canUpdate)
		{
			$this->WriteToTrackingService(GetMessage('TASKS_UTA_NO_PERMISSIONS'), 0, CBPTrackingType::Error);
			$this->ErrorMessage = GetMessage('TASKS_UTA_NO_PERMISSIONS');
		}
		else
		{
			$documentService = $this->workflow->GetService("DocumentService");

			// Attribute to the workflow starter when no explicit
			// ModifiedBy. method_exists guards bizproc being older than tasks.
			$modifiedBy = method_exists($this, 'getModifiedByOrStarter')
				? $this->getModifiedByOrStarter()
				: $this->ModifiedBy
			;
			try
			{
				$documentService->UpdateDocument($documentId, $fieldValue, $modifiedBy);
			}
			catch (Exception $e)
			{
				$this->writeToTrackingService($e->getMessage(), 0, CBPTrackingType::Error);
				$this->ErrorMessage = $e->getMessage();
			}
		}

		return CBPActivityExecutionStatus::Closed;
	}

	private function canUpdateResolvedTaskTarget(
		array $documentId,
		array $fieldValue,
		?Tasks\Access\Model\TaskModel $newTask,
	): ?bool
	{
		$rootDocumentId = $this->getRootActivity()->getDocumentId();
		$rootDocumentId = is_array($rootDocumentId) ? $rootDocumentId : [];

		if ($rootDocumentId === $documentId)
		{
			return null;
		}

		$actorId = $this->resolveTargetAccessActorId();

		$canUpdate = TargetDocumentAccessGuard::checkResolvedTarget(
			$rootDocumentId,
			$documentId,
			$this->resolveUpdateAction($newTask),
			$actorId,
			$newTask,
		);
		if ($canUpdate === true)
		{
			return true;
		}

		if (count($fieldValue) === 1 && array_key_exists('DEADLINE', $fieldValue))
		{
			return TargetDocumentAccessGuard::checkResolvedTarget(
				$rootDocumentId,
				$documentId,
				Tasks\Access\ActionDictionary::ACTION_TASK_DEADLINE,
				$actorId,
			);
		}

		return $canUpdate;
	}

	private function canUpdateRootTaskTarget(
		int $taskId,
		string $documentType,
		array $fieldValue,
		?Tasks\Access\Model\TaskModel $newTask,
	): bool
	{
		$actorId = $this->resolveRootTaskAccessActorId($documentType);
		if ($actorId <= 0)
		{
			return $this->canUpdateWithoutActor($documentType, $fieldValue);
		}

		$canUpdate = Tasks\Access\TaskAccessController::can(
			$actorId,
			$this->resolveUpdateAction($newTask),
			$taskId,
			$newTask,
		);
		if ($canUpdate)
		{
			return true;
		}

		if (count($fieldValue) === 1 && array_key_exists('DEADLINE', $fieldValue))
		{
			return Tasks\Access\TaskAccessController::can(
				$actorId,
				Tasks\Access\ActionDictionary::ACTION_TASK_DEADLINE,
				$taskId,
			);
		}

		return false;
	}

	/**
	 * Old workflow instances and system starts may carry no subject at all. Restores the master
	 * behaviour for them: project, scrum project and bizproc documents stay writable, but only
	 * through the field set that was already writable before this feature.
	 */
	private function canUpdateWithoutActor(string $documentType, array $fieldValue): bool
	{
		if (array_intersect_key($fieldValue, array_flip(self::FIELDS_OPENED_BY_FEATURE)) !== [])
		{
			return false;
		}

		return Tasks\Integration\Bizproc\Document\Task::isProjectTask($documentType)
			|| Tasks\Integration\Bizproc\Document\Task::isScrumProjectTask($documentType)
			|| Tasks\Integration\Bizproc\Document\Task::isBizprocTask($documentType)
		;
	}

	private function affectsAccessModel(array $fieldValue): bool
	{
		return array_intersect_key($fieldValue, array_flip(self::ACCESS_MODEL_FIELDS)) !== [];
	}

	protected function resolveUpdateAction(?Tasks\Access\Model\TaskModel $newTask): string
	{
		return $newTask === null
			? Tasks\Access\ActionDictionary::ACTION_TASK_EDIT
			: Tasks\Access\ActionDictionary::ACTION_TASK_SAVE
		;
	}

	private function createUpdatedTaskModel(int $taskId, array $fieldValue): Tasks\Access\Model\TaskModel
	{
		$task = clone Tasks\Access\Model\TaskModel::createFromId($taskId);

		if (array_key_exists('GROUP_ID', $fieldValue))
		{
			$task->setGroupId((int)$fieldValue['GROUP_ID']);
		}

		if (array_key_exists('PARENT_ID', $fieldValue))
		{
			$task->setParentId((int)$fieldValue['PARENT_ID']);
		}

		$userFieldRoles = [
			'RESPONSIBLE_ID' => Tasks\Access\Role\RoleDictionary::ROLE_RESPONSIBLE,
			'ACCOMPLICES' => Tasks\Access\Role\RoleDictionary::ROLE_ACCOMPLICE,
			'AUDITORS' => Tasks\Access\Role\RoleDictionary::ROLE_AUDITOR,
		];
		$updatedUserFieldRoles = array_intersect_key($userFieldRoles, $fieldValue);
		if ($updatedUserFieldRoles !== [])
		{
			$members = $task->getMembers();
			foreach ($updatedUserFieldRoles as $fieldName => $role)
			{
				$userIds = (array)\CBPHelper::ExtractUsers(
					$fieldValue[$fieldName],
					Tasks\Integration\Bizproc\Document\Task::resolveDocumentId($taskId),
					$fieldName === 'RESPONSIBLE_ID',
				);
				$userIds = array_values(array_filter(array_map('intval', $userIds)));
				if ($fieldName !== 'RESPONSIBLE_ID' || $userIds !== [])
				{
					$members[$role] = $userIds;
				}
			}
			$task->setMembers($members);
		}

		return $task;
	}

	private function resolveRootTaskAccessActorId(string $documentType): int
	{
		if (Tasks\Integration\Bizproc\Document\Task::isPlanTask($documentType))
		{
			return Tasks\Integration\Bizproc\Document\Task::resolvePlanId($documentType);
		}

		if (Tasks\Integration\Bizproc\Document\Task::isPersonalTask($documentType))
		{
			return Tasks\Integration\Bizproc\Document\Task::resolvePersonId($documentType);
		}

		return $this->resolveTargetAccessActorId();
	}
}
