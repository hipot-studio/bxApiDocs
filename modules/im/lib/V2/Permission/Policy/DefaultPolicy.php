<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Permission\Policy;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Permission;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Permission\ActionGroup;

class DefaultPolicy implements ActionAccessPolicy
{
	public function check(Chat $chat, int $userId, Action $action, mixed $target): ?bool
	{
		$userRights = $chat->getRole();
		$rightByType = Permission::getRoleForActionByType($chat->getExtendedType(false), $action);
		$actionGroup = ActionGroup::tryFromAction($action);

		$manageRights = match ($actionGroup)
		{
			ActionGroup::ManageUi => $chat->getManageUI(),
			ActionGroup::ManageUsersAdd => $chat->getManageUsersAdd(),
			ActionGroup::ManageUsersDelete => $chat->getManageUsersDelete(),
			ActionGroup::ManageSettings => $chat->getManageSettings(),
			ActionGroup::ManageMessages => $chat->getManageMessages(),
			ActionGroup::ManageMessagesAutoDelete => $chat->getManageMessagesAutoDelete(),
			ActionGroup::ManageGuestInvites => $chat->getManageGuestInvites(),
			ActionGroup::ManageDelete => $chat->getManageDelete(),
			// both rules are read off the chat the question is asked of, and for a thread that chat is
			// its parent: a rule changed later has to reach the discussions opened before it
			ActionGroup::ThreadCreate => $chat->getManageThreadCreate(),
			ActionGroup::ThreadPost => $chat->getManageThreadPost(),
			default => Chat::ROLE_GUEST,
		};

		return Permission::compareRole($userRights, $manageRights)
			&& Permission::compareRole($userRights, $rightByType)
			&& Permission::canDoActionByUserType($userId, $action, $target)
		;
	}
}
