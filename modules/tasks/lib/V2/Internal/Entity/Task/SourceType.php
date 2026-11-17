<?php

namespace Bitrix\Tasks\V2\Internal\Entity\Task;

enum SourceType: string
{
	case CHAT = 'chat';
	case MAIL_MESSAGE = 'mail_message';
	case CRM_ACTIVITY = 'crm_activity';
}
