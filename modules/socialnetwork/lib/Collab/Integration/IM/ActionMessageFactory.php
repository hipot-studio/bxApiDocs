<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM;

use Bitrix\Socialnetwork\Collab\Integration\IM\Message\AddBotActionMessage;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\AddGuestActionMessage;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\AddUserActionMessage;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\CollabCreateActionMessage;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\ConvertGroupToCollabMessage;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\ConvertGroupToCollabMessageRich;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\CopyLinkActionMessage;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\ActionMessageInterface;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\AcceptUserActionMessage;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\ExcludeUserActionMessage;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\InviteGuestActionMessage;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\InviteUserActionMessage;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\JoinUserActionMessage;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\LeaveUserActionMessage;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\ProjectCreateActionMessage;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\ProjectCreateActionMessageRich;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\RegenerateLinkActionMessage;
use Bitrix\Socialnetwork\Helper\InstanceTrait;

class ActionMessageFactory
{
	use InstanceTrait;

	public function getActionMessage(ActionType $action, int $collabId, int $senderId): ActionMessageInterface
	{
		return match ($action)
		{
			ActionType::AcceptUser => new AcceptUserActionMessage($collabId, $senderId),
			ActionType::InviteGuest => new InviteGuestActionMessage($collabId, $senderId),
			ActionType::InviteUser => new InviteUserActionMessage($collabId, $senderId),
			ActionType::CreateCollab => new CollabCreateActionMessage($collabId, $senderId),
			ActionType::AddUser => new AddUserActionMessage($collabId, $senderId),
			ActionType::AddGuest => new AddGuestActionMessage($collabId, $senderId),
			ActionType::AddBot => new AddBotActionMessage($collabId, $senderId),
			ActionType::LeaveUser => new LeaveUserActionMessage($collabId, $senderId),
			ActionType::ExcludeUser => new ExcludeUserActionMessage($collabId, $senderId),
			ActionType::CopyLink => new CopyLinkActionMessage($collabId, $senderId),
			ActionType::RegenerateLink => new RegenerateLinkActionMessage($collabId, $senderId),
			ActionType::ConvertGroupToCollab => new ConvertGroupToCollabMessage($collabId, $senderId),
			ActionType::ConvertGroupToCollabRich => new ConvertGroupToCollabMessageRich($collabId, $senderId),
			ActionType::CreateProject => new ProjectCreateActionMessage($collabId, $senderId),
			ActionType::CreateProjectRich => new ProjectCreateActionMessageRich($collabId, $senderId),
			default => new JoinUserActionMessage($collabId, $senderId),
		};
	}
}
