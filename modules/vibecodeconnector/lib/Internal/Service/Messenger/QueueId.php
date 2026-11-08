<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger;

enum QueueId: string
{
	case BalancePull = 'vibecodeconnector.balance_pull';
	case AppChatDeletion = 'vibecodeconnector.app_chat_deletion';
	case UserListPull = 'vibecodeconnector.user_list_pull';
	case UserEvent = 'vibecodeconnector.user_event';
	case AppChatMembership = 'vibecodeconnector.app_chat_membership';
}
