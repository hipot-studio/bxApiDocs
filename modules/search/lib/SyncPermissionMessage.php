<?php
namespace Bitrix\Search;

use Bitrix\Main\Messenger\Entity\AbstractMessage;

/**
 * Class SyncPermissionMessage
 *
 * Messenger message for syncing permissions of search content entries.
 * This message is used to transfer permission update data between modules.
 *
 * @package Bitrix\Search
 */
class SyncPermissionMessage extends AbstractMessage
{
	/**
	 * SyncPermissionMessage constructor.
	 *
	 * @param string $permission The permission group to update.
	 * @param mixed $filter Optional filter conditions for the update.
	 * @param int $lastId Last processed ID for pagination/sequence.
	 */
	public function __construct(
		public readonly string $permission,
		public readonly mixed $filter = false,
		public int $lastId = 0,
	)
	{
	}
}
