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
		if (empty($userIds))
		{
			return;
		}

		foreach ($this->ancestorIterator->ancestorsOf($childChat) as $ancestor)
		{
			$levelRelations = $ancestor->getRelationsByUserIds($userIds);
			if ($levelRelations->isEmpty())
			{
				break;
			}

			(new RecentUpdateMeta($ancestor, $levelRelations->getUserIds()))->send();
			$userIds = $levelRelations->getUserIds();
		}
	}
}
