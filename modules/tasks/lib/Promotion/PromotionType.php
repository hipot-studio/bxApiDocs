<?php

namespace Bitrix\Tasks\Promotion;

enum PromotionType: string
{
	case TASKS_AI = 'tasks_ai';
	case TASKS_NEW_CARD = 'tasks_new_card';
	case TASKS_NEW_CHAT_BUTTON = 'tasks_new_chat_button';
	case DEADLINE_BANNER = 'tasks_deadline_banner';
	case RESPONSIBLE_BANNER = 'tasks_responsible_banner';
	case DEADLINE_AHA = 'tasks_deadline_aha';
	case TASKS_CONSULTATION_BUTTON = 'tasks_consultation_button';
	case TASKS_KANBAN_FIELD_SORT = 'tasks_kanban_field_sort';
	case TASKS_FILES_VIEW = 'tasks_files_view';
}
