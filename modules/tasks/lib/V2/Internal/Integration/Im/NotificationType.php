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
	case TaskStatusChanged = 'task_status_changed';
	case TaskTimerStarted = 'task_timer_started';
	case TaskTimerStopped = 'task_timer_stopped';
	// Add more as needed
}
