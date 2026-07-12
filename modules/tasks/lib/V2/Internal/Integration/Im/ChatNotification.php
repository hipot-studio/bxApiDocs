<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\AbstractNotify;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\ChatActionLinkService;
use Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service\FeatureService;
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
				newResponsible: $args['newResponsible'] ?? null,
				isNewMember: $args['isNewMember'] ?? false,
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
				chatActionLinkService: $this->getChatActionLinkService(),
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
			NotificationType::GroupAdded => new Action\NotifyGroupAdded(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				chatActionLinkService: $this->getChatActionLinkService(),
				featureService: new FeatureService(),
				group: $args['group'] ?? null,
			),
			NotificationType::GroupChanged => new Action\NotifyGroupChanged(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				chatActionLinkService: $this->getChatActionLinkService(),
				featureService: new FeatureService(),
				newGroup: $args['newGroup'] ?? null,
			),
			NotificationType::GroupRemoved => new Action\NotifyGroupRemoved(
				task: $task,
				sender: $this,
				featureService: new FeatureService(),
				triggeredBy: $args['triggeredBy'] ?? null,
				group: $args['group'] ?? null,
			),
			NotificationType::TaskOverdue => new Action\NotifyTaskOverdue(
				task: $task,
				sender: $this,
				chatActionLinkService: $this->getChatActionLinkService(),
			),
			NotificationType::TaskOverdueSoon => new Action\NotifyTaskOverdueSoon(
				task: $task,
				sender: $this,
				chatActionLinkService: $this->getChatActionLinkService(),
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
				chatActionLinkService: $this->getChatActionLinkService(),
				triggeredBy: $args['triggeredBy'] ?? null,
			),
			NotificationType::TaskTimerStopped => new Action\NotifyTaskTimerStopped(
				task: $task,
				sender: $this,
				chatActionLinkService: $this->getChatActionLinkService(),
				triggeredBy: $args['triggeredBy'] ?? null,
				seconds: $args['seconds'] ?? null,
				elapsedTimeId: $args['elapsedTimeId'] ?? null,
			),
			NotificationType::TaskTimersStopped => new Action\NotifyTaskTimersStopped(
				task: $task,
				sender: $this,
				chatActionLinkService: $this->getChatActionLinkService(),
				triggeredBy: $args['triggeredBy'] ?? null,
				seconds: $args['seconds'] ?? null,
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
				chatActionLinkService: $this->getChatActionLinkService(),
				fileCount: $args['fileCount'] ?? 1,
				checklistName: $args['checklistName'] ?? '',
				checkListId: $args['itemId'] ?? null,
				itemIds: $args['itemIds'] ?? [],
			),
			NotificationType::ChecklistCompleted => new Action\NotifyChecklistCompleted(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				chatActionLinkService: $this->getChatActionLinkService(),
				checklistName: $args['checklistName'] ?? '',
				checkListId: $args['itemId'] ?? null,
			),
			NotificationType::ChecklistAdded => new Action\NotifyChecklistAdded(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				chatActionLinkService: $this->getChatActionLinkService(),
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
				chatActionLinkService: $this->getChatActionLinkService(),
				resultText: $args['resultText'] ?? '',
				dateTs: $args['dateTs'] ?? '',
				fileIds: $args['fileIds'] ?? [],
				resultId: $args['resultId'] ?? 0,
			),
			NotificationType::ResultModified => new Action\NotifyResultModified(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				chatActionLinkService: $this->getChatActionLinkService(),
				dateTs: $args['dateTs'] ?? '',
				resultId: $args['resultId'] ?? 0,
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
				chatActionLinkService: $this->getChatActionLinkService(),
				messageId: $args['messageId'] ?? 0,
				dateTs: $args['dateTs'] ?? 0,
				resultId: $args['resultId'] ?? 0,
				type: $args['type'] ?? null,
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
			NotificationType::TaskAttachmentChanged => new Action\NotifyFileChanged(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
				fileId: $args['fileId'] ?? null,
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
			NotificationType::TaskStageChanged => new Action\NotifyTaskStageChanged(
				task: $task,
				sender: $this,
				triggeredBy: $args['triggeredBy'] ?? null,
				newStage: $args['newStage'] ?? null,

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
				chatActionLinkService: $this->getChatActionLinkService(),
			),
			NotificationType::TaskMarkChanged => new Action\NotifyMarkChanged(
				task: $task,
				triggeredBy: $args['triggeredBy'] ?? null,
				markBefore: $args['markBefore'] ?? null,
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
	private function getChatActionLinkService(): ChatActionLinkService
	{
		return Container::getInstance()->get(ChatActionLinkService::class);
	}
}
