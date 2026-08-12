<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Notification;

enum NotificationGroup: string
{
	case Members = 'members';
	case Content = 'content';

	public function getLabelKey(): string
	{
		return match ($this)
		{
			self::Members => 'SONET_V2_NOTIFY_GROUP_MEMBERS',
			self::Content => 'SONET_V2_NOTIFY_GROUP_CONTENT',
		};
	}
}
