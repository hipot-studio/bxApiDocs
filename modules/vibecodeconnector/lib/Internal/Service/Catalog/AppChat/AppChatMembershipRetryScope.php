<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

enum AppChatMembershipRetryScope
{
	case ItemSpecific;
	case GlobalTransient;
}
