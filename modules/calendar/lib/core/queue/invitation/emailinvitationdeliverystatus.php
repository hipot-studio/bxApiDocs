<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Core\Queue\Invitation;

enum EmailInvitationDeliveryStatus
{
	case Delivered;
	case NotAssembled;
	case Refused;
}
