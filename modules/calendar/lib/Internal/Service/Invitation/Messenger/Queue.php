<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Service\Invitation\Messenger;

enum Queue: string
{
	case EmailInvitation = 'calendar_email_invitation';
}
