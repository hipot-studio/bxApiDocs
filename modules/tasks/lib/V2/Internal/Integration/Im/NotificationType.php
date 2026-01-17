<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

enum NotificationType: string
{
	case ChatCreatedForExistingTask = 'chat_created_for_existing_task';
	case TaskHasForumComments = 'task_has_forum_comments';
	case TaskHasLegacyChat = 'task_has_legacy_chat';
	case TaskCreated = 'task_created';
	case ResponsibleChanged = 'responsible_changed';
	case OwnerChanged = 'owner_changed';
	case DeadlineChanged = 'deadline_changed';
	case AuditorsChanged = 'auditors_changed';
	case AccomplicesChanged = 'accomplices_changed';
	case GroupChanged = 'group_changed';
	case TaskOverdue = 'task_overdue';
	case TaskOverdueSoon = 'task_overdue_soon';
	case TaskStatusChanged = 'task_status_changed';
	case TaskTimerStarted = 'task_timer_started';
	case TaskTimerStopped = 'task_timer_stopped';
	case TaskTimersStopped = 'task_timers_stopped';
	case ChecklistItemsAdded = 'checklist_items_added';
	case ChecklistItemsDeleted = 'checklist_items_deleted';
	case ChecklistItemsModified = 'checklist_items_modified';
	case ChecklistItemsCompleted = 'checklist_items_completed';
	case ChecklistItemsUnchecked = 'checklist_items_unchecked';
	case ChecklistSingleItemCompleted = 'checklist_single_item_completed';
	case ChecklistSingleItemUnchecked = 'checklist_single_item_unchecked';
	case ChecklistAuditorAssigned = 'checklist_auditor_assigned';
	case ChecklistAccompliceAssigned = 'checklist_accomplice_assigned';
	case ChecklistFilesAdded = 'checklist_files_added';
	case ChecklistCompleted = 'checklist_completed';
	case ChecklistGroupedOperations = 'checklist_grouped_operations';
	case ChecklistAdded = 'checklist_added';
	case ChecklistDeleted = 'checklist_deleted';
	case TaskStatusPinged = 'task_status_pinged';
	case ResultAdded = 'result_added';
	case ResultModified = 'result_modified';
	case ResultDeleted = 'result_deleted';
	case ResultFromMessage = 'result_from_message';
	case ResultRequested = 'result_requested';
	case TaskDescriptionChanged = 'task_description_changed';
	case TaskPriorityChanged = 'task_priority_changed';
	case TaskCrmItemsChanged = 'task_crm_items_changed';
	case TaskAttachmentAdded = 'task_attachment_added';
	case TaskAttachmentRemoved = 'task_attachment_removed';
	case TaskAttachmentChanged = 'task_attachment_changed';
	case TaskMovedToBacklog = 'task_moved_to_backlog';
	case TaskDeleted = 'task_deleted';
	case TaskStageChanged = 'task_stage_changed';
	case OnboardingInvitedResponsibleAccept = 'onboarding_invited_responsible_accept';
	case OnboardingInvitedResponsibleNotAcceptOneDay = 'onboarding_invited_responsible_not_accept_one_day';
	case OnboardingInvitedResponsibleNotViewTaskTwoDays = 'onboarding_invited_responsible_not_view_task_two_days';
	case ElapsedTimeAdded = 'elapsed_time_added';
	// Add more as needed
}
