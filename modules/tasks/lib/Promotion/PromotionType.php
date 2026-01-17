<?php

namespace Bitrix\Tasks\Promotion;

enum PromotionType: string
{
	case TASKS_AI = 'tasks_ai';
	case TASKS_NEW_CARD = 'tasks_new_card';
	case TASKS_NEW_CHAT_BUTTON = 'tasks_new_chat_button';
}
