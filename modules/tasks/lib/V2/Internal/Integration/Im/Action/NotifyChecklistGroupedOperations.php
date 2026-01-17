<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;
use Bitrix\Tasks\V2\Internal\Service\Esg\Detector\ChecklistOperation;
use Bitrix\Tasks\V2\Internal\Service\Esg\Detector\ChecklistOperationGroup;
use Bitrix\Tasks\V2\Internal\Service\Task\Role;

class NotifyChecklistGroupedOperations extends AbstractNotify
{
	private readonly string $checklistName;
	private ChecklistOperationGroup $operationGroup;

	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly array $operations = [],
	)
	{
		$operationGroup = $operations['operationGroup'] ?? null;

		if (!$operationGroup instanceof ChecklistOperationGroup)
		{
			return;
		}

		$this->operationGroup = $operationGroup;
		$this->checklistName = $operations['checklistName'] ?? '';

		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getRecipients(): array
	{
		foreach ($this->operationGroup->getOperations() as $operation)
		{
			$roles[] = match ($operation->getType()) {
				NotificationType::ChecklistItemsCompleted => [Role::Responsible, Role::Accomplice],
				NotificationType::ChecklistItemsModified => [Role::Responsible, Role::Accomplice],
				NotificationType::ChecklistItemsDeleted => [Role::Responsible, Role::Accomplice],
				NotificationType::ChecklistItemsAdded => [Role::Responsible, Role::Accomplice],
				NotificationType::ChecklistItemsUnchecked => [Role::Responsible, Role::Accomplice],
				NotificationType::ChecklistFilesAdded => [Role::Responsible, Role::Accomplice],
				NotificationType::ChecklistAccompliceAssigned => [Role::Responsible, Role::Accomplice],
				NotificationType::ChecklistAuditorAssigned => [Role::Responsible, Role::Accomplice, Role::Auditor],
				default => [],
			};
		}

		return array_unique(array_filter(array_merge(...$roles)), flags: \SORT_REGULAR);
	}

	public function getMessageCode(): string
	{
		return match ($this->triggeredBy?->getGender()) {
			Entity\User\Gender::Male   => 'TASKS_IM_CHECKLIST_GROUPED_OPERATIONS_M',
			Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_GROUPED_OPERATIONS_F',
			default                    => 'TASKS_IM_CHECKLIST_GROUPED_OPERATIONS_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#CHECKLIST_NAME#' => $this->checklistName,
			'#OPERATIONS#' => implode("\n", $this->buildBulletList()),
		];
	}

	/* @var ChecklistOperation[] $operations */
	private function getCheckListId(array $operations): int
	{
		foreach ($operations as $operation)
		{
			if (
				$operation instanceof ChecklistOperation
				&& $operation->getItemId() > 0
			)
			{
				return $operation->getItemId();
			}
		}

		return 0;
	}

	private function getItemIds(array $operations): array
	{
		$items = [];

		/* @var ChecklistOperation $operation */
		foreach ($operations as $operation)
		{
			array_push($items, ...$operation->getItemIds());
		}

		return array_values(array_unique($items));
	}

	private function buildBulletList(): array
	{
		$bullets = [];
		$operationCounts = $this->operationGroup->getOperationCounts();

		foreach ($operationCounts as $operationType => $count)
		{
			$operationType = NotificationType::tryFrom($operationType);
			$bullet = $this->formatOperationBullet($operationType, $count);

			if (null === $bullet)
			{
				continue;
			}

			$bullets[] = $bullet;
		}

		return $bullets;
	}

	private function formatOperationBullet(?NotificationType $operationType, int $count): ?string
	{
		return match ($operationType)
		{
			NotificationType::ChecklistItemsCompleted =>
				$this->getMessagePlural(
					code: $this->getGroupedMessageCode($operationType),
					count: $count,
					data: ['#COUNT#' => $count],
				),
			NotificationType::ChecklistSingleItemCompleted =>
				$this->getMessagePlural(
					code: $this->getGroupedMessageCode($operationType),
					count: $count,
					data: ['#COUNT#' => 1]
				),
			NotificationType::ChecklistItemsModified =>
				$this->getMessagePlural(
					code: $this->getGroupedMessageCode($operationType),
					count: $count,
					data: ['#COUNT#' => $count]
				),
			NotificationType::ChecklistItemsDeleted =>
				$this->getMessagePlural(
					code: $this->getGroupedMessageCode($operationType),
					count: $count,
					data: ['#COUNT#' => $count]
				),
			NotificationType::ChecklistItemsAdded =>
				$this->getMessagePlural(
					code: $this->getGroupedMessageCode($operationType),
					count: $count,
					data: ['#COUNT#' => $count]
				),
			NotificationType::ChecklistItemsUnchecked =>
				$this->getMessagePlural(
					code: $this->getGroupedMessageCode($operationType),
					count: $count,
					data: ['#COUNT#' => $count]
				),
			NotificationType::ChecklistSingleItemUnchecked =>
				$this->getMessagePlural(
					code: $this->getGroupedMessageCode($operationType),
					count: $count,
					data: ['#COUNT#' => 1],
				),
			NotificationType::ChecklistFilesAdded =>
				$this->getMessagePlural(
					code: $this->getGroupedMessageCode($operationType),
					count: $count,
					data: ['#COUNT#' => $count]
				),
			NotificationType::ChecklistAccompliceAssigned =>
				$this->getMessage($this->getGroupedMessageCode($operationType)),
			NotificationType::ChecklistAuditorAssigned => 
				$this->getMessage($this->getGroupedMessageCode($operationType)),
			default => null,
		};
	}

	private function getGroupedMessageCode(NotificationType $operationType): string
	{
		return match ($operationType) {
			NotificationType::ChecklistSingleItemCompleted => match ($this->triggeredBy?->getGender()) {
				Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_GROUPED_COMPLETED_F',
				default                    => 'TASKS_IM_CHECKLIST_GROUPED_COMPLETED',
			},
			NotificationType::ChecklistItemsCompleted => match ($this->triggeredBy?->getGender()) {
				Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_GROUPED_COMPLETED_F',
				default                    => 'TASKS_IM_CHECKLIST_GROUPED_COMPLETED',
			},
			NotificationType::ChecklistItemsModified => match ($this->triggeredBy?->getGender()) {
				Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_GROUPED_MODIFIED_F',
				default                    => 'TASKS_IM_CHECKLIST_GROUPED_MODIFIED',
			},
			NotificationType::ChecklistItemsDeleted => match ($this->triggeredBy?->getGender()) {
				Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_GROUPED_DELETED_F',
				default                    => 'TASKS_IM_CHECKLIST_GROUPED_DELETED',
			},
			NotificationType::ChecklistItemsAdded => match ($this->triggeredBy?->getGender()) {
				Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_GROUPED_ADDED_F',
				default                    => 'TASKS_IM_CHECKLIST_GROUPED_ADDED',
			},
			NotificationType::ChecklistItemsUnchecked => match ($this->triggeredBy?->getGender()) {
				Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_GROUPED_UNCHECKED_F',
				default                    => 'TASKS_IM_CHECKLIST_GROUPED_UNCHECKED',
			},
			NotificationType::ChecklistFilesAdded => match ($this->triggeredBy?->getGender()) {
				Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_GROUPED_FILES_ADDED_F',
				default                    => 'TASKS_IM_CHECKLIST_GROUPED_FILES_ADDED',
			},
			NotificationType::ChecklistAccompliceAssigned => match ($this->triggeredBy?->getGender()) {
				Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_GROUPED_ACCOMPLICE_ASSIGNED_F',
				default                    => 'TASKS_IM_CHECKLIST_GROUPED_ACCOMPLICE_ASSIGNED',
			},
			NotificationType::ChecklistAuditorAssigned => match ($this->triggeredBy?->getGender()) {
				Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_GROUPED_AUDITOR_ASSIGNED_F',
				default                    => 'TASKS_IM_CHECKLIST_GROUPED_AUDITOR_ASSIGNED',
			},
			NotificationType::ChecklistFilesAdded => match ($this->triggeredBy?->getGender()) {
				Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_GROUPED_FILES_ADDED_F',
				default                    => 'TASKS_IM_CHECKLIST_GROUPED_FILES_ADDED',
			},
			NotificationType::ChecklistSingleItemUnchecked => match ($this->triggeredBy?->getGender()) {
				Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_GROUPED_UNCHECKED_F',
				default                    => 'TASKS_IM_CHECKLIST_GROUPED_UNCHECKED',
			},
			default => null,
		};
	}
}
