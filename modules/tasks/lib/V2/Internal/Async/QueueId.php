<?php

namespace Bitrix\Tasks\V2\Internal\Async;

enum QueueId: string
{
	case AddDavSync = 'add_task_dav_sync';
	case AddSearchIndex = 'add_task_search_index';
	case AddLastActivity = 'add_task_last_activity';
	case AddScenario = 'add_task_scenario';
	case AddSendNotification = 'add_task_send_notification';

	case UpdateDavSync = 'update_task_dav_sync';
	case UpdateSearchIndex = 'update_task_search_index';
	// TODO: Remove when UpdateTopic queue is completely empty.
	case UpdateTopic = 'update_task_topic';
	case UpdateSendNotification = 'update_task_send_notification';
	case RecountSort = 'recount_task_sort';

	case AddFlowStages = 'add_flow_stages';
	case EventDispatcher = 'event_dispatcher';
	case ReadSystemMessage = 'read_system_message';

	case MigrateRecentTasks = 'migrate_recent_tasks';
	case TemplateRecent = 'update_template_recent';
	case ReadAllMessages = 'read_all_messages';
}
