<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Service\Invitation;

use Bitrix\Main\Config\Option;

class InvitationQueueFeature
{
	public static function isOn(): bool
	{
		return Option::get('calendar', 'email_invitation_messenger', 'Y') === 'Y';
	}
}
