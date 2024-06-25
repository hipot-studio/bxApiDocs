<?php

namespace Bitrix\Bizproc\UI;

use Bitrix\Bizproc\Workflow\Entity\WorkflowUserCommentTable;
use Bitrix\Bizproc\Workflow\WorkflowState;
use Bitrix\Bizproc\WorkflowInstanceTable;
use Bitrix\Main\Application;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class WorkflowUserView implements \JsonSerializable
{
	private WorkflowState $workflow;
	private array $tasks;
	private array $myTasks;
	private int $userId;

	public function __construct(WorkflowState $workflow, int $userId)
	{
		$this->workflow = $workflow;
		$this->userId = $userId;

		$this->tasks = \CBPViewHelper::getWorkflowTasks($workflow['ID'], true, true);
		$this->myTasks = $this->getMyTasks();
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->getId(),
			'data' => [
				'id' => $this->getId(),
				'typeName' => $this->getTypeName(),
				'itemName' => $this->getName(),
				'itemDescription' => $this->getDescription(),
				'itemTime' => $this->getTime(),
				'statusText' => $this->getStatusText(),
				'faces' => $this->getFaces(),
				'tasks' => $this->getTasks(),
				'authorId' => $this->getAuthorId(),
				'newCommentsCounter' => $this->getCommentCounter(),
			],
		];
	}

	public function getId(): string
	{
		return $this->workflow->getId();
	}

	public function getName(): mixed
	{
		if ($this->getTasks())
		{
			return current($this->getTasks())['name'];
		}

		if (!$this->isWorkflowAuthorView())
		{
			foreach (['RUNNING', 'COMPLETED'] as $taskState)
			{
				foreach ($this->tasks[$taskState] as $task)
				{
					if (in_array($this->userId, array_column($task['USERS'], 'USER_ID')))
					{
						return html_entity_decode($task['~NAME']);
					}
				}
			}
		}

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();

		return html_entity_decode(
			$documentService->getDocumentName($this->workflow->getComplexDocumentId()) ?? ''
		);
	}

	public function getDescription(): ?string
	{
		$task = $this->getTasks()[0] ?? null;
		if ($task)
		{
			return \CBPViewHelper::prepareTaskDescription(
				\CBPHelper::convertBBtoText(
					preg_replace('|\n+|', "\n", trim($task['description']))
				)
			);
		}

		return null;
	}

	public function getStatusText(): mixed
	{
		return $this->workflow->getStateTitle();
	}

	public function getIsCompleted(): bool
	{
		return !WorkflowInstanceTable::exists($this->getId());
	}

	public function getCommentCounter(): int
	{
		$row = WorkflowUserCommentTable::getList([
			'filter' => [
				'=WORKFLOW_ID' => $this->getId(),
				'=USER_ID' => $this->userId,
			],
			'select' => ['UNREAD_CNT'],
		])->fetch();

		return $row ? (int)$row['UNREAD_CNT'] : 0;
	}

	public function getTasks(): array
	{
		return $this->myTasks;
	}

	public function getAuthorId(): mixed
	{
		return $this->workflow->getStartedBy();
	}

	private function getTime(): ?string
	{
		$culture = Application::getInstance()->getContext()->getCulture();
		$dateTimeFormat =
			$culture
				? $culture->getLongDateFormat() . ' ' . $culture->getShortTimeFormat()
				: 'j F Y G:i'
		;

		if ($this->getTasks())
		{
			return $this->formatDateTime($dateTimeFormat, current($this->getTasks())['createdDate'] ?? null);
		}

		if ($this->isWorkflowAuthorView())
		{
			return $this->formatDateTime($dateTimeFormat, $this->workflow->getStarted());
		}

		foreach (['RUNNING', 'COMPLETED'] as $taskState)
		{
			foreach ($this->tasks[$taskState] as $task)
			{
				foreach ($task['USERS'] as $taskUser)
				{
					if ((int)$taskUser['USER_ID'] === $this->userId)
					{
						return $this->formatDateTime($dateTimeFormat, $taskUser['DATE_UPDATE'] ?? null);
					}
				}
			}
		}

		return $this->formatDateTime($dateTimeFormat, $this->workflow->getStarted());
	}

	public function getFaces(): WorkflowFacesView
	{
		return new WorkflowFacesView($this->getId(), $this->myTasks[0]['id'] ?? null);
	}

	public function getWorkflowState(): WorkflowState
	{
		return $this->workflow;
	}

	private function getMyTasks(): array
	{
		$userId = $this->userId;

		$myTasks = array_filter(
			$this->tasks['RUNNING'],
			static function($task) use ($userId) {
				$waitingUsers = array_filter(
					$task['USERS'],
					static fn ($user) => ((int)$user['STATUS'] === \CBPTaskUserStatus::Waiting),
				);

				return in_array($userId, array_column($waitingUsers, 'USER_ID'));
			},
		);

		return $this->prepareMyTasks(array_map(
			static function($task) {
				$controls = \CBPDocument::getTaskControls($task);

				return [
					'id' => (int)$task['ID'],
					'name' => html_entity_decode($task['~NAME']),
					'description' => $task['~DESCRIPTION'],
					'isInline' => \CBPHelper::getBool($task['IS_INLINE']),
					'controls' => [
						'buttons' => $controls['BUTTONS'] ?? null,
						'fields' => $controls['FIELDS'] ?? null,
					],
					'createdDate' => $task['~CREATED_DATE'] ?? null,
					'delegationType' => $task['~DELEGATION_TYPE'] ?? null,
				];
			},
			array_values($myTasks),
		));
	}

	private function prepareMyTasks(array $myTasks): array
	{
		$isRpa = $this->workflow->getModuleId() === 'rpa';
		$workflowId = $this->getId();

		foreach ($myTasks as &$task)
		{
			if (!empty($task['controls']['buttons']))
			{
				foreach ($task['controls']['buttons'] as &$button)
				{
					if (!empty($button['TEXT']))
					{
						$button['TEXT'] = html_entity_decode(htmlspecialcharsback($button['TEXT']));
					}
				}
			}

			$task['url'] = $isRpa
				? "/rpa/task/id/{$task['id']}/"
				: sprintf(
					'/bitrix/components/bitrix/bizproc.workflow.info/?workflow=%s&task=%s&user=%d',
					$workflowId,
					$task['id'],
					$this->userId
				)
			;

			$task['userId'] = $this->userId;
		}

		return $myTasks;
	}

	public function getTypeName(): mixed
	{
		$this->workflow->fillTemplate();
		if (
			$this->workflow->getModuleId() !== 'lists'
			&& !empty($this->workflow->getTemplate()?->getName())
		)
		{
			return $this->workflow->getTemplate()?->getName();
		}

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();

		$complexDocumentType = null;
		try
		{
			$complexDocumentType = $documentService->getDocumentType($this->workflow->getComplexDocumentId());
		}
		catch (SystemException | \Exception $exception)
		{}

		return $complexDocumentType ? $documentService->getDocumentTypeCaption($complexDocumentType) : null;
	}

	private function isWorkflowAuthorView(): bool
	{
		return $this->getAuthorId() === $this->userId;
	}

	private function formatDateTime(string $format, $datetime): ?string
	{
		if ($datetime instanceof DateTime)
		{
			$datetime = (string)$datetime;
		}

		if (is_string($datetime) && DateTime::isCorrect($datetime))
		{
			$timestamp = (new DateTime($datetime))->getTimestamp();

			return FormatDate($format, $timestamp);
		}

		return null;
	}
}
