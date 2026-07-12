<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Convert;

enum ConvertTrackedHandler: string
{
	case SendConverterEvent = 'SendConverterEvent';
	case SendConverterPushEvent = 'SendConverterPushEvent';
	case ConvertChat = 'ConvertChat';
	case SyncProjectChatMembers = 'SyncProjectChatMembers';
	case UpdateGroupTasksChat = 'UpdateGroupTasksChat';
	case UpdateGroupEventsChat = 'UpdateGroupEventsChat';
}
