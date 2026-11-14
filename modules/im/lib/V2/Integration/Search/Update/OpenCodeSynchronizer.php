<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Update;

use Bitrix\Im\V2\Integration\Search\Access\OpenCodeReachResolver;
use Bitrix\Im\V2\Integration\Search\PermissionBuilder;
use Bitrix\Im\V2\Integration\Search\SearchAdapter;

/**
 * Brings the shared access code of a chat to what the reach of the open contexts says about it now.
 *
 * The one answer, and the same one for every caller: the walk over the open contexts of a portal that
 * collected its index before the code existed, and the walk over the descendants of a chat whose
 * conversion moved the reach of the whole subtree under it.
 *
 * A chat the shared code no longer reaches keeps nothing of it: the code comes off and the names of
 * its members are queued instead. The other way round the code is handed out, and the names already
 * baked stay where they are - they are the narrower of the two answers, so nobody is shown anything
 * the messenger would not show.
 */
final class OpenCodeSynchronizer
{
	public function __construct(
		private readonly SearchAdapter $adapter,
		private readonly OpenCodeReachResolver $reachResolver,
	)
	{}

	/**
	 * @return bool Whether anything was handed over to the queue of the walk. The caller raises its
	 *              agent once for a portion and not once per chat.
	 */
	public function sync(int $chatId): bool
	{
		if ($chatId <= 0)
		{
			return false;
		}

		if (!$this->reachResolver->isReachedById($chatId))
		{
			if (!$this->adapter->deletePermission(PermissionBuilder::OPEN_ACCESS_CODE, ['=PARAM1' => $chatId]))
			{
				ChatConversionPermissionStepper::enqueueRevocation(PermissionBuilder::OPEN_ACCESS_CODE, $chatId);
			}

			ChatConversionPermissionStepper::enqueue($chatId);

			return true;
		}

		if ($this->adapter->addPermission(PermissionBuilder::OPEN_ACCESS_CODE, ['=PARAM1' => $chatId]))
		{
			return false;
		}

		ChatConversionPermissionStepper::enqueueGrant(PermissionBuilder::OPEN_ACCESS_CODE, $chatId);

		return true;
	}
}
