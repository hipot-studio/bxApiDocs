<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Tree\ChatAncestorIterator;
use Bitrix\Im\V2\Pull\Event\RecentUpdateMeta;

/**
 * Walks chat ancestors and sends RecentUpdateMeta to inform clients about parent-chat metadata
 * without moving the recent item (dateLastActivity = null).
 *
 * On each level the recipient set is narrowed to users who actually have a relation in that
 * ancestor; iteration stops as soon as no recipients remain.
 */
class AncestorContextSender
{
	public function __construct(private readonly ChatAncestorIterator $ancestorIterator) {}

	/**
	 * @param int[] $userIds
	 */
	public function send(Chat $childChat, array $userIds): void
	{
		$this->sendForChain($this->ancestorIterator->ancestorsOf($childChat), $userIds);
	}

	/**
	 * Like send(), but the walk starts from (and includes) $fromChat itself. Used on detach: the detached
	 * chat no longer points at its former parent, so the former parent chain is walked explicitly.
	 *
	 * @param int[] $userIds
	 */
	public function sendForChainIncluding(Chat $fromChat, array $userIds): void
	{
		$this->sendForChain($this->ancestorIterator->selfAndAncestorsOf($fromChat), $userIds);
	}

	/**
	 * @param iterable<int, Chat> $chats
	 * @param int[] $userIds
	 */
	private function sendForChain(iterable $chats, array $userIds): void
	{
		if (empty($userIds))
		{
			return;
		}

		foreach ($chats as $chat)
		{
			$levelRelations = $chat->getRelationsByUserIds($userIds);
			if ($levelRelations->isEmpty())
			{
				break;
			}

			(new RecentUpdateMeta($chat, $levelRelations->getUserIds()))->send();
			$userIds = $levelRelations->getUserIds();
		}
	}
}
