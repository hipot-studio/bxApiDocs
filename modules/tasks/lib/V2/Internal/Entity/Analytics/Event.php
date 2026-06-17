<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Analytics;

enum Event: string
{
	case TaskCreate = 'task_create';
	case TaskDelete = 'task_delete';
	case TaskView = 'task_view';
	case TaskComplete = 'task_complete';
	case CommentAdd = 'comment_add';
	case StatusSummaryAdd = 'status_summary_add';
	case SubTaskAdd = 'subtask_add';
	case OverdueCountersOn = 'overdue_counters_on';
	case CommentsCountersOn = 'comments_counters_on';
	case FlowCreateStart = 'flow_create_start';
	case FlowCreateFinish = 'flow_create_finish';
	case FlowEditStart = 'flow_edit_start';
	case FlowEditFinish = 'flow_edit_finish';
	case FlowsView = 'flows_view';
	case LeadView = 'lead_view';
	case TasksProjectsView = 'tasks_projects_view';
	case AddViewer = 'add_viewer';
	case AddCoexecutor = 'add_coexecutor';
	case TaskUpdate = 'task_update';
	case ClickCreate = 'click_create';
	case AssigneeChange = 'assignee_change';
	case DeadlineSet = 'deadline_set';
	case AddChecklist = 'add_checklist';
	case TaskCreateWithChecklist = 'task_create_with_checklist';
	case ClickTaskLink = 'click_task_link';
	case TaskDelegation = 'task_delegation';
	case NotificationSent = 'notification_sent';
	case PatternTaskCreate = 'pattern_task_create';
	case TimeTracking = 'time_tracking';
}
