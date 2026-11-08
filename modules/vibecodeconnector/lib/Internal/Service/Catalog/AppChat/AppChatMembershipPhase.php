<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

enum AppChatMembershipPhase: string
{
	case Audience = 'audience';
	case Members = 'members';
}
