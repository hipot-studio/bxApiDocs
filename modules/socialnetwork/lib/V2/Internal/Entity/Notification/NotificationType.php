<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Notification;

enum NotificationType: string
{
	case MemberAddEmployee = 'member_add_employee';
	case MemberAddGuest = 'member_add_guest';
	case MemberInvite = 'member_invite';
	case MemberJoin = 'member_join';
	case MemberLeave = 'member_leave';
	case MemberExclude = 'member_exclude';
	case MemberAddBot = 'member_add_bot';
	case ContentFeedPost = 'content_feed_post';

	public function getWireKey(): string
	{
		return match ($this)
		{
			self::MemberAddEmployee => 'mae',
			self::MemberAddGuest => 'mag',
			self::MemberInvite => 'mi',
			self::MemberJoin => 'mj',
			self::MemberLeave => 'ml',
			self::MemberExclude => 'mx',
			self::MemberAddBot => 'mab',
			self::ContentFeedPost => 'cfp',
		};
	}

	public function getGroup(): NotificationGroup
	{
		return match ($this)
		{
			self::MemberAddEmployee,
			self::MemberAddGuest,
			self::MemberInvite,
			self::MemberJoin,
			self::MemberLeave,
			self::MemberExclude,
			self::MemberAddBot => NotificationGroup::Members,
			self::ContentFeedPost => NotificationGroup::Content,
		};
	}

	public function getDefaultCounter(): bool
	{
		return match ($this)
		{
			self::MemberAddEmployee => false,
			self::MemberAddGuest => true,
			self::MemberInvite => true,
			self::MemberJoin => false,
			self::MemberLeave => false,
			self::MemberExclude => false,
			self::MemberAddBot => false,
			self::ContentFeedPost => true,
		};
	}

	public function getLabelKey(): string
	{
		return match ($this)
		{
			self::MemberAddEmployee => 'SONET_V2_NOTIFY_TYPE_ADD_EMPLOYEE',
			self::MemberAddGuest => 'SONET_V2_NOTIFY_TYPE_ADD_GUEST',
			self::MemberInvite => 'SONET_V2_NOTIFY_TYPE_INVITE',
			self::MemberJoin => 'SONET_V2_NOTIFY_TYPE_JOIN',
			self::MemberLeave => 'SONET_V2_NOTIFY_TYPE_LEAVE',
			self::MemberExclude => 'SONET_V2_NOTIFY_TYPE_EXCLUDE',
			self::MemberAddBot => 'SONET_V2_NOTIFY_TYPE_ADD_BOT',
			self::ContentFeedPost => 'SONET_V2_NOTIFY_TYPE_FEED_POST',
		};
	}

	public function isMemberGeneration(): bool
	{
		return $this->getGroup() === NotificationGroup::Members;
	}

	public function isFeedGeneration(): bool
	{
		return $this === self::ContentFeedPost;
	}
}
