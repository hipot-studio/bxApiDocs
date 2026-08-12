<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnGroupDelete;

use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\LogImMessageLinkService;

/**
 * Handler for the classic socialnetwork::OnSocNetGroupDelete event.
 *
 * Link cleanup on group/project deletion: deleting a group (incl.
 * collab) drops its chat and posts, but the "feed post <-> system message"
 * link rows for that group would be left orphaned (a per-post OnSocNetLogDelete
 * is not guaranteed on cascading group deletion). We purge them in one query
 * by GROUP_ID so the table does not accumulate garbage.
 *
 * Does not affect counter correctness (a lookup on a missing chat is a no-op);
 * this is purely storage cleanup.
 *
 * The event is published classically (ExecuteModuleEventEx with scalar
 * $groupId), hence registered via registerEventHandler, NOT via the typed
 * EventDispatcher.
 */
final class ClearImLinks
{
	/**
	 * @param int $groupId deleted group/project id
	 */
	public static function onGroupDelete($groupId): void
	{
		$groupId = (int)$groupId;
		if ($groupId <= 0)
		{
			return;
		}

		Container::getInstance()
			->get(LogImMessageLinkService::class)
			->unlinkByGroupId($groupId)
		;
	}
}
