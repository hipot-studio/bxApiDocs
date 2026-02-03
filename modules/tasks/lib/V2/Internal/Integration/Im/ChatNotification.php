<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\AbstractNotify;
use Bitrix\Tasks\V2\Internal\Result\Result;

class ChatNotification implements ChatNotificationInterface, MessageSenderInterface
{
	/**
	 * Generic notification method.
	 * @param NotificationType $type
	 * @param Entity\Task $task
	 * @param array $args Additional arguments for replacements (e.g., triggeredBy, oldResponsible, etc.)
	 */
	public function notify(NotificationType $type, Entity\Task $task, array $args = []): void
	{
		$this->loadMessages();

		$notification = match ($type) {
			NotificationType::ChatCreatedForExistingTask => new Action\NotifyChatCreatedForExistingTask(
				task: $task,
				sender: $this,
			),
			NotificationType::TaskHasForumComments => new Action\NotifyTaskHasForumComments(
				task: $task,
				sender: $this,
			),
			NotificationType::TaskHasLegacyChat => new Action\NotifyTaskHasLegacyChat(
				task: $task,
				sender: $this,
				args: $args,
			),
			NotificationType::TaskCreated => new Action\NotifyTaskCreated(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null
			),
			NotificationType::ResponsibleChanged => new Action\NotifyResponsibleChanged(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				oldResponsible: $args['oldResponsible'] ?? null,
				newResponsible: $args['newResponsible'] ?? null
			),
			NotificationType::OwnerChanged => new Action\NotifyOwnerChanged(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				oldOwner: $args['oldOwner'] ?? null,
				newOwner: $args['newOwner'] ?? null
			),
			NotificationType::DeadlineChanged => new Action\NotifyDeadlineChanged(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				newDeadlineTs: $args['newDeadlineTs'] ?? null,
				oldDeadlineTs: $args['oldDeadlineTs'] ?? null
			),
			NotificationType::AuditorsChanged => new Action\NotifyAuditorsChanged(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				oldAuditors: $args['oldAuditors'] ?? null,
				newAuditors: $args['newAuditors'] ?? null,
				newAddMembers: $args['newAddMembers'] ?? null,
			),
			NotificationType::AccomplicesChanged => new Action\NotifyAccomplicesChanged(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				oldAccomplices: $args['oldAccomplices'] ?? null,
				newAccomplices: $args['newAccomplices'] ?? null,
				newAddMembers: $args['newAddMembers'] ?? null,
			),
			NotificationType::GroupChanged => new Action\NotifyGroupChanged(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				newGroup: $args['newGroup'] ?? null,
				oldGroup: $args['oldGroup'] ?? null
			),
			NotificationType::TaskOverdue => new Action\NotifyTaskOverdue(
				task: $task,
				sender: $this,
			),
			NotificationType::TaskOverdueSoon => new Action\NotifyTaskOverdueSoon(
				task: $task,
				sender: $this,
			),
			NotificationType::TaskStatusChanged => new Action\NotifyTaskStatusChanged(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
				oldStatus: $args['oldStatus'] ?? null,
				newStatus: $args['newStatus'] ?? null,
			),
			NotificationType::TaskTimerStarted => new Action\NotifyTaskTimerStarted(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null
			),
			NotificationType::TaskTimerStopped => new Action\NotifyTaskTimerStopped(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				seconds: $args['seconds'] ?? null,
			),
			NotificationType::TaskTimersStopped => new Action\NotifyTaskTimersStopped(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				seconds: $args['seconds'] ?? null,
			),
			NotificationType::ChecklistItemsAdded => new Action\NotifyChecklistItemsAdded(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				itemCount: $args['itemCount'] ?? 1,
				checklistName: $args['checklistName'] ?? '',
				checkListId: $args['itemId'] ?? null,
				itemIds: $args['itemIds'] ?? [],
			),
			NotificationType::ChecklistItemsDeleted => new Action\NotifyChecklistItemsDeleted(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				itemCount: $args['itemCount'] ?? 1,
				checklistName: $args['checklistName'] ?? '',
				checkListId: $args['itemId'] ?? null,
			),
			NotificationType::ChecklistItemsModified => new Action\NotifyChecklistItemsModified(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				itemCount: $args['itemCount'] ?? 1,
				checklistName: $args['checklistName'] ?? '',
				checkListId: $args['itemId'] ?? null,
				itemIds: $args['itemIds'] ?? [],
			),
			NotificationType::ChecklistItemsCompleted => new Action\NotifyChecklistItemsCompleted(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				itemCount: $args['itemCount'] ?? 1,
				checklistName: $args['checklistName'] ?? '',
				checkListId: $args['itemId'] ?? null,
				itemIds: $args['itemIds'] ?? [],
			),
			NotificationType::ChecklistItemsUnchecked => new Action\NotifyChecklistItemsUnchecked(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				itemCount: $args['itemCount'] ?? 1,
				checklistName: $args['checklistName'] ?? '',
				checkListId: $args['itemId'] ?? null,
				itemIds: $args['itemIds'] ?? [],
			),
			NotificationType::ChecklistSingleItemCompleted => new Action\NotifyChecklistSingleItemCompleted(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				checklistName: $args['checklistName'] ?? '',
				itemName: $args['itemName'] ?? '',
				checkListId: $args['itemId'] ?? null,
				itemIds: $args['itemIds'] ?? [],
			),
			NotificationType::ChecklistSingleItemUnchecked => new Action\NotifyChecklistSingleItemUnchecked(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				checklistName: $args['checklistName'] ?? '',
				itemName: $args['itemName'] ?? '',
				checkListId: $args['itemId'] ?? null,
				itemIds: $args['itemIds'] ?? [],
			),
			NotificationType::ChecklistAuditorAssigned => new Action\NotifyChecklistAuditorAssigned(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				checklistName: $args['checklistName'] ?? '',
				assignee: $args['assignee'] ?? null
			),
			NotificationType::ChecklistAccompliceAssigned => new Action\NotifyChecklistAccompliceAssigned(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				checklistName: $args['checklistName'] ?? '',
				assignee: $args['assignee'] ?? null
			),
			NotificationType::ChecklistFilesAdded => new Action\NotifyChecklistFilesAdded(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				fileCount: $args['fileCount'] ?? 1,
				checklistName: $args['checklistName'] ?? '',
				checkListId: $args['itemId'] ?? null,
				itemIds: $args['itemIds'] ?? [],
			),
			NotificationType::ChecklistCompleted => new Action\NotifyChecklistCompleted(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				checklistName: $args['checklistName'] ?? '',
				checkListId: $args['itemId'] ?? null,
			),
			NotificationType::ChecklistGroupedOperations => new Action\NotifyChecklistGroupedOperations(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				operations: $args
			),
			NotificationType::ChecklistAdded => new Action\NotifyChecklistAdded(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				itemsCount: $args['itemCount'] ?? 1,
				checklistName: $args['checklistName'] ?? '',
				checkListId: $args['itemId'] ?? null,
			),
			NotificationType::ChecklistDeleted => new Action\NotifyChecklistDeleted(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				checklistName: $args['checklistName'] ?? ''
			),
			NotificationType::TaskStatusPinged => new Action\NotifyTaskStatusPinged(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
			),
			NotificationType::ResultAdded => new Action\NotifyResultAdded(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				resultText: $args['resultText'] ?? '',
				dateTs: $args['dateTs'] ?? '',
				fileIds: $args['fileIds'] ?? [],
			),
			NotificationType::ResultModified => new Action\NotifyResultModified(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				dateTs: $args['dateTs'] ?? '',

			),
			NotificationType::ResultDeleted => new Action\NotifyResultDeleted(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				dateTs: $args['dateTs'] ?? '',
			),
			NotificationType::ResultFromMessage => new Action\NotifyResultFromMessage(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				messageId: $args['messageId'] ?? 0,
				dateTs: $args['dateTs'] ?? 0,
				type: $args['type'] ?? null,
			),
			NotificationType::ResultRequested => new Action\NotifyResultRequested(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
			),
			NotificationType::TaskDescriptionChanged => new Action\NotifyTaskDescriptionChanged(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
				oldDescription: $args['oldDescription'] ?? null,
				newDescription: $args['newDescription'] ?? null
			),
			NotificationType::TaskPriorityChanged => new Action\NotifyPriorityChanged(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
				priority: $args['priority'] ?? null,
			),
			NotificationType::TaskCrmItemsChanged => new Action\NotifyCrmItemsChanged(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
			),
			NotificationType::TaskAttachmentChanged => new Action\NotifyFilesChanged(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
				fileIds: $args['fileIds'] ?? [],
			),
			NotificationType::TaskAttachmentAdded => new Action\NotifyFilesAdded(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
				fileIds: $args['fileIds'] ?? [],
			),
			NotificationType::TaskAttachmentRemoved => new Action\NotifyFilesRemoved(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
				fileIds: $args['fileIds'] ?? [],
			),
			NotificationType::TaskMovedToBacklog => new Action\NotifyTaskMovedToBacklog(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
			),
			NotificationType::TaskDeleted => new Action\NotifyTaskDeleted(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
			),
			NotificationType::OnboardingInvitedResponsibleAccept => new Action\OnboardingInvitedResponsibleAccept(
				task: $task,
				sender: $this,
			),
			NotificationType::OnboardingInvitedResponsibleNotAcceptOneDay => new Action\OnboardingInvitedResponsibleNotAcceptOneDay(
				task: $task,
				sender: $this,
			),
			NotificationType::OnboardingInvitedResponsibleNotViewTaskTwoDays => new Action\OnboardingInvitedResponsibleNotViewTaskTwoDays(
				task: $task,
				sender: $this,
			),
			NotificationType::ElapsedTimeAdded => new Action\NotifyElapsedTimeAdded(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
				elapsedTime: $args['elapsedTime'] ?? null,
			),
			default => null,
		};

		if (null === $notification)
		{
			return;
		}

		if ($notification instanceof Action\ShouldSend)
		{
			$this->sendMessage($task, $notification);
		}
	}

	public function sendMessage(Entity\Task $task, AbstractNotify $notification): Result
	{
		return Container::getInstance()->get(MessageSenderInterface::class)->sendMessage($task, $notification);
	}

	private function loadMessages(): void
	{
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/V2/Internal/Integration/Im/ChatNotification.php');
	}
}
