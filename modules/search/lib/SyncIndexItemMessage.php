<?php
namespace Bitrix\Search;

use Bitrix\Main\Messenger\Entity\AbstractMessage;

/**
 * Class SyncIndexItemMessage
 *
 * Messenger message for syncing of search content entry.
 *
 * @package Bitrix\Search
 */
class SyncIndexItemMessage extends AbstractMessage
{
	/**
	 * SyncIndexItemMessage constructor.
	 *
	 * @param int $searchContentId The identifier to sync.
	 */
	public function __construct(
		public readonly int $searchContentId = 0,
	)
	{
	}
}
