<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Permission\Policy;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Permission\Action;

/**
 * Structural half of the thread actions, in front of the arithmetic of roles.
 *
 * It lives here and not in the caller because canDo() is asked directly too - by a condition of the
 * interface, by the assembly of a DTO, by another module - and a chat whose type admits no threads,
 * or whose switch is off, must answer no to all of them, whatever the role of the user is.
 */
class ThreadActionPolicy implements ActionAccessPolicy
{
	public function __construct(
		private readonly DefaultPolicy $defaultPolicy,
	) {}

	public function check(Chat $chat, int $userId, Action $action, mixed $target): ?bool
	{
		// the pair "this type -> comment", asked of the tree relation registry, which stays the single
		// place where the rule is written down
		if (!$chat->allowsComments())
		{
			return false;
		}

		// null for the channel family, whose comments keep the rules they have always had and never
		// reach the thread actions at all
		$settingsChat = $chat->getThreadSettingsChat();

		if ($settingsChat === null || !$settingsChat->getThreadsEnabled())
		{
			return false;
		}

		return $this->defaultPolicy->check($chat, $userId, $action, $target);
	}
}
