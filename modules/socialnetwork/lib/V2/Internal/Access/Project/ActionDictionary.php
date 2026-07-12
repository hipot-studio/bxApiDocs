<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Project;

use Bitrix\SocialNetwork\Collab\Access\CollabDictionary;

enum ActionDictionary: string
{
	case Read = CollabDictionary::VIEW;
	case Update = CollabDictionary::UPDATE;
	case Delete = CollabDictionary::DELETE;
	case Invite = CollabDictionary::INVITE;
	case Leave = CollabDictionary::LEAVE;
	case Exclude = CollabDictionary::EXCLUDE;
	case SetModerator = CollabDictionary::SET_MODERATOR;
	case ExcludeModerator = CollabDictionary::EXCLUDE_MODERATOR;
	case SetOwner = CollabDictionary::SET_OWNER;
	case CopyLink = CollabDictionary::COPY_LINK;
	case Convert = CollabDictionary::CONVERT;
}
