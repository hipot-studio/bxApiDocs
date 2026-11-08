<?php
namespace Bitrix\Search;

use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Entity\ProcessingParam\DelayParam;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;

/**
 * Class SyncPermissionReceiver
 *
 * Receiver for syncing permissions of search content entries.
 * The filtered selection is materialized into the b_search_content_right_sync queue table once,
 * by the delivery that finds no QUEUE_FILLED marker in the message. Every following delivery takes
 * a portion of that table by its primary key, pushes the permissions of each entry into the search
 * index and drops the entry, so the progress of a message is the table itself, not a stored id.
 * Concurrent handlers are serialized by a named connection lock, so overlapping messages
 * collapse into a single pass over the shared queue table.
 *
 * @package Bitrix\Search
 */
class SyncPermissionReceiver extends AbstractReceiver
{
	// same value as the module default in search/default_option.php
	private const DEFAULT_STEP_DURATION = 20;
	private const MAX_STEP_DURATION = 30;
	private const MAX_ENTRIES_PER_STEP = 1000;

	private const SYNC_TABLE = 'b_search_content_right_sync';
	private const LOCK_NAME = 'search_sync_perm';

	// lastId carries no identifier: a value the previous format could never store, as it wrote real ids only
	private const QUEUE_FILLED = -1;

	/**
	 * Processes incoming messages to update search content permissions.
	 *
	 * @param SyncPermissionMessage $message The message containing permission update data.
	 * @return void
	 */
	protected function process(MessageInterface $message): void
	{
		$connection = \CDatabase::GetModuleConnection('search')->getConnection();

		if ($message->lastId !== self::QUEUE_FILLED)
		{
			$this->fillSyncTable($connection, $message->filter);

			// the marker is set before the portion, so a recoverable error does not repeat the expensive selection
			$message->lastId = self::QUEUE_FILLED;
		}

		// the lock is taken without waiting: a busy handler would hold the process and the connection for nothing
		if (!$connection->lock(self::LOCK_NAME, 0))
		{
			$message->lastId = self::QUEUE_FILLED;

			// the holder of the lock finishes its step within getStepDuration(), so the message wakes up after it
			$message->send($this->queueId, [new DelayParam($this->getStepDuration())]);

			return;
		}

		try
		{
			$this->processPortion($connection, $message);
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}
	}

	/**
	 * Materializes the filtered selection of search content into the queue table.
	 *
	 * @param Connection $connection Module connection.
	 * @param mixed $filter Filter of the message, in terms of CSearch::__PrepareFilter().
	 * @return void
	 */
	private function fillSyncTable(Connection $connection, mixed $filter): void
	{
		$incSites = false;
		$sqlWhere = \CSearch::__PrepareFilter($filter, $incSites);

		$selectSql = '
			SELECT sc.ID
			FROM b_search_content sc
			' . ($incSites ? 'INNER JOIN b_search_content_site scsite ON sc.ID=scsite.SEARCH_CONTENT_ID' : '') . '
			' . ($sqlWhere ? 'WHERE ' . $sqlWhere : '') . '
		';

		$connection->queryExecute(
			$connection->getSqlHelper()->getInsertIgnore(
				self::SYNC_TABLE,
				' (SEARCH_CONTENT_ID) ',
				$selectSql,
			)
		);
	}

	/**
	 * Pushes the permissions of a single portion of the queue table into the search index.
	 * Returns the message back to the queue when at least one entry has been handled.
	 *
	 * @param Connection $connection Module connection.
	 * @param SyncPermissionMessage $message The message being processed.
	 * @return void
	 * @throws RecoverableMessageException
	 */
	private function processPortion(Connection $connection, SyncPermissionMessage $message): void
	{
		$etime = microtime(true) + $this->getStepDuration();
		$processedEntries = 0;

		$entryList = $connection->query('
			SELECT SEARCH_CONTENT_ID
			FROM ' . self::SYNC_TABLE . '
			ORDER BY SEARCH_CONTENT_ID DESC
		', self::MAX_ENTRIES_PER_STEP);

		while ($entry = $entryList->fetch())
		{
			$contentId = (int)$entry['SEARCH_CONTENT_ID'];

			try
			{
				\CSearchFullText::getInstance()->update($contentId, [
					'PERMISSIONS' => $this->getGroupCodes($connection, $contentId),
				]);
			}
			catch (\Bitrix\Main\DB\ConnectionException $e)
			{
				throw new RecoverableMessageException('Error processing search queue.', 0, $e);
			}

			$connection->queryExecute('
				DELETE FROM ' . self::SYNC_TABLE . '
				WHERE SEARCH_CONTENT_ID = ' . $contentId . '
			');

			$processedEntries++;

			if ($etime < microtime(true))
			{
				break;
			}
		}

		if ($processedEntries > 0)
		{
			$message->send($this->queueId);
		}
	}

	/**
	 * Returns the permission group codes of a search content entry.
	 *
	 * @param Connection $connection Module connection.
	 * @param int $contentId Identifier of the search content entry.
	 * @return string[]
	 */
	private function getGroupCodes(Connection $connection, int $contentId): array
	{
		$groups = [];

		$groupList = $connection->query('
			SELECT GROUP_CODE FROM b_search_content_right
			WHERE SEARCH_CONTENT_ID = ' . $contentId . '
		');
		while ($group = $groupList->fetch())
		{
			$groupCode = $group['GROUP_CODE'];
			$groups[$groupCode] = $groupCode;
		}

		return array_values($groups);
	}

	/**
	 * Returns the time slice duration in seconds for a single message processing.
	 * The module option allows an unlimited step, which is not applicable to a queue worker:
	 * it runs in the tail of a web request, under the PHP execution time limit.
	 *
	 * @return int
	 */
	private function getStepDuration(): int
	{
		$duration = (int)Option::get('search', 'max_execution_time');

		return $duration > 0 ? min($duration, self::MAX_STEP_DURATION) : self::DEFAULT_STEP_DURATION;
	}
}
