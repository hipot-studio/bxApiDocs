<?php

namespace Bitrix\Mobile\Tab\Preset\Integration\Messenger;

use Bitrix\Main\Messenger\Entity\AbstractMessage;

final class MenuCacheInvalidationMessage extends AbstractMessage
{
	public const QUEUE_ID = 'mobile.menu_cache_invalidation';

	/**
	 * @param int[] $userIds
	 */
	public function __construct(public readonly array $userIds)
	{
	}
}
