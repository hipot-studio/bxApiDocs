<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Integration\Search\Query\MessageSearchQuery;
use Bitrix\Im\V2\Integration\Search\Result\RawWindow;
use Bitrix\Im\V2\Integration\Search\Result\SnippetSegment;
use Bitrix\Im\V2\Integration\Search\Update\AgentRegistrar;
use Bitrix\Im\V2\Integration\Search\Update\ChatConversionPermissionStepper;
use Bitrix\Im\V2\Logger;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * The only place in the module where CSearch is called.
 * Write methods answer true when the call went through, false when the module is missing
 * or the call threw: the throwable is logged here and never leaves the adapter.
 */
class SearchAdapter
{
	private const MODULE_ID = 'im';
	private const AUTHOR_ENTITY_TYPE_ID = 'im_user';
	private const LOG_TAG = 'message-search-index';
	private const READ_LOG_TAG = 'message-search-facade';
	private const PERMISSION_LOG_TAG = 'message-search-permission';
	private const DEFAULT_MAX_BODY_SIZE = 100000;
	private const PERMISSION_LOG_THRESHOLD_OPTION = 'message_search_perm_log_threshold';
	private const DEFAULT_PERMISSION_LOG_THRESHOLD = 5000;
	private const PERMISSION_MEASUREMENT_BUDGET_OPTION = 'message_search_perm_log_measures';
	/**
	 * Off by default. Every measurement is a read of the entries under the filter up to one past the
	 * threshold, and the command of the rights walks that very range right after it: an ordinary
	 * synchronization of the ACL would pay thousands of reads a process for a line of a log. The
	 * measurement is raised by hand on the portal a large walk is being looked into on.
	 */
	private const DEFAULT_PERMISSION_MEASUREMENT_BUDGET = 0;

	/**
	 * Permission operations of this process, keyed by the filter of the count. Entries are counted no
	 * further than one past the threshold, so above it the number means 'more than the threshold'.
	 *
	 * @var array<string, array{operation: string, chatId: int, boundary: ?string, entries: int,
	 *                          threshold: int, commands: int, logged: bool}>
	 */
	private array $permissionOperations = [];

	/**
	 * Keys of the operations no dump of the log has looked at yet. What a dump has looked at is never
	 * looked at again: the entries of a record are counted once and decide there and then whether it is
	 * written down, so a later dump could only walk everything the process has gathered anew.
	 *
	 * @var array<string, true>
	 */
	private array $unreportedPermissionOperations = [];

	/**
	 * Measurements this process has left. One of them reads up to threshold+1 entries of the index, and
	 * the operation of the rights then walks the very same range again: a single portion of the open
	 * context walk hands over as many filters as it holds chats, and measuring every one of them would
	 * cost the step more than the work it came for. The budget is a property of the process, so a walk
	 * measures other chats on its next step.
	 */
	private int $measurementsLeft;
	private bool $isPermissionLogScheduled = false;

	public function __construct(
		private readonly SnippetParser $snippetParser = new SnippetParser(),
	)
	{
		$this->measurementsLeft = self::getMeasurementBudget();
	}

	/**
	 * @return bool Whether the entry of the message stands in the index after the call.
	 *
	 * The core answers the identifier of the row it wrote, and zero or false when it wrote none: an
	 * Add() that failed on a row nothing else has created is a refusal that throws nothing at all. It
	 * is read here and not dropped: a walk that carries its cursor past such a message leaves the
	 * index without an entry nothing ever comes back for.
	 */
	public function index(int $messageId, array $entry, bool $overwrite): bool
	{
		$entryId = false;

		$isCalled = $this->run(
			static function () use ($messageId, $entry, $overwrite, &$entryId): void {
				$entryId = self::indexInServerTime($messageId, $entry, $overwrite);
			},
			fn () => $this->requeueForSync($messageId),
		);

		if (!$isCalled)
		{
			return false;
		}

		if ((int)$entryId > 0)
		{
			return true;
		}

		(new Logger(self::LOG_TAG))->log(
			"the index wrote no entry of the message {$messageId} and threw nothing, the write is refused"
		);

		return false;
	}

	/**
	 * The newest message the index holds an entry of, as the number its identifier is.
	 *
	 * ITEM_ID is a varchar, so neither MAX() over it nor an order by it is the order of the numbers:
	 * both call '99' the largest of an archive that has reached 100. The answer is probed instead, one
	 * length at a time from the longest downwards - identifiers of one length lie in a range of the
	 * very index the slice is read by, and the first length that answers holds the maximum.
	 *
	 * @return int|null Zero when the module holds no entry at all; null when the index cannot be read
	 *                  at all, which is a different answer and may not be taken for an empty index.
	 */
	public function getLastIndexedMessageId(): ?int
	{
		if (!Loader::includeModule('search'))
		{
			return null;
		}

		try
		{
			$connection = \CDatabase::GetModuleConnection('search');
			$newestMessageId = (string)self::getNewestMessageId();
			$longest = strlen($newestMessageId);

			for ($length = $longest; $length >= 1; $length--)
			{
				$itemId = $this->probeLongestItemId(
					$connection,
					$length,
					$length === $longest ? $newestMessageId : str_repeat('9', $length),
				);

				if ($itemId !== null)
				{
					return (int)$itemId;
				}
			}

			return 0;
		}
		catch (\Throwable $exception)
		{
			$this->logThrowable($exception);

			return null;
		}
	}

	/**
	 * The largest entry of the module whose identifier is exactly $length digits long, or null when
	 * the module holds none of that length.
	 *
	 * Both ends of the range are given, so the read is a range of UX_B_SEARCH_CONTENT taken backwards
	 * and not a scan of the slice. Identifiers of other lengths fall inside the range as strings, so
	 * the length is asked for as well: it discards rows and is not what the read is driven by.
	 */
	private function probeLongestItemId(\CDatabase $connection, int $length, string $upperBound): ?string
	{
		$sql = $connection->TopSql(
			"SELECT ITEM_ID FROM b_search_content WHERE MODULE_ID = '" . self::MODULE_ID . "'"
			. " AND ITEM_ID >= '" . ('1' . str_repeat('0', $length - 1)) . "'"
			. " AND ITEM_ID <= '" . $connection->ForSql($upperBound) . "'"
			. ' AND LENGTH(ITEM_ID) = ' . $length
			. ' ORDER BY ITEM_ID DESC',
			1,
		);

		$row = $connection->Query($sql)->Fetch();

		return is_array($row) ? (string)$row['ITEM_ID'] : null;
	}

	/**
	 * Upper end of the probe above. An answer below the true maximum costs the catching up walk a wider
	 * overlap and nothing else.
	 */
	private static function getNewestMessageId(): int
	{
		$row = MessageTable::query()
			->setSelect(['ID'])
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? (int)$row['ID'] : 0;
	}

	/**
	 * A keyset portion of the entries of the module: the entry itself and the message it describes.
	 *
	 * The walk goes by ITEM_ID because (MODULE_ID, ITEM_ID) is the one order of the table an index
	 * holds: an order by ID is covered by nothing, so every step would sort the whole slice of the
	 * module. ITEM_ID is a varchar, so the order is that of strings, which a full pass is fine with -
	 * the pair is unique, so no entry is read twice or skipped over.
	 *
	 * The filter by MODULE_ID is the whole safety of the hygiene cleanup that reads this: entries of
	 * foreign modules may not be read and may not be removed.
	 *
	 * @param string $afterItemId Cursor of the walk: the ITEM_ID the previous portion ended at, an
	 *                            empty string at its start.
	 *
	 * @return array<int, array{id: int, itemId: string, messageId: int}>|null Ascending by ITEM_ID, so
	 *         the last row of the portion is the cursor of the next one. Null when the index cannot be
	 *         read: an empty portion means the walk is over, and a failure may not be mistaken for that.
	 */
	public function listOwnRecords(string $afterItemId, int $limit): ?array
	{
		if (!Loader::includeModule('search'))
		{
			return null;
		}

		try
		{
			// the connection of the search module: on a portal with a database of its own for the search
			// the connection of im would reach another one
			$connection = \CDatabase::GetModuleConnection('search');
			$sql = $connection->TopSql(
				"SELECT ID, ITEM_ID FROM b_search_content WHERE MODULE_ID = '" . self::MODULE_ID . "'"
				. " AND ITEM_ID > '" . $connection->ForSql($afterItemId) . "'"
				. ' ORDER BY ITEM_ID ASC',
				max(1, $limit),
			);

			$result = $connection->Query($sql);

			$rows = [];
			while ($row = $result->Fetch())
			{
				$rows[] = [
					'id' => (int)$row['ID'],
					'itemId' => (string)$row['ITEM_ID'],
					'messageId' => (int)$row['ITEM_ID'],
				];
			}

			return $rows;
		}
		catch (\Throwable $exception)
		{
			$this->logThrowable($exception);

			return null;
		}
	}

	public function deleteByMessageIds(array $messageIds): bool
	{
		$itemIds = array_values(array_unique(array_map('intval', $messageIds)));
		if ($itemIds === [])
		{
			return true;
		}

		// the documents are read before the deletion and not after it: CSearch::DeleteIndex() takes the
		// SQL row of an entry off first and only then tells the engine, so a call the engine threw on
		// leaves a document nothing addresses any more - a repeat of the very same deletion finds no row,
		// walks nothing and answers success, while the text stays in the engine for good
		$documentIds = $this->listDocumentIdsOfMessages($itemIds);

		if ($this->deleteByFilter(['@ITEM_ID' => $itemIds]))
		{
			return true;
		}

		// the identifiers go into the queue and not into a retry of their own: the engine is refusing
		// right now, and a second command in the same hit would be refused as well
		ChatConversionPermissionStepper::enqueueDocumentDeletion($documentIds, count($itemIds));
		// the executor is raised here and not by the caller: this is the one place the documents are
		// queued from, and a caller that walks its own portion again - the hygiene walk does exactly
		// that - finds no SQL row of theirs any more and would leave them waiting for an unrelated
		// registration
		AgentRegistrar::registerQueueRemainder();

		return false;
	}

	/**
	 * Takes the documents themselves out of the engine, by the identifiers of the rows they were written
	 * under. The only command of the domain that addresses the engine directly: it is used where the SQL
	 * rows are already gone and no filter of the core reaches the documents any more.
	 *
	 * @param int[] $documentIds
	 */
	public function deleteDocuments(array $documentIds): bool
	{
		$documentIds = array_values(array_unique(array_filter(
			array_map('intval', $documentIds),
			static fn (int $documentId): bool => $documentId > 0,
		)));
		if ($documentIds === [])
		{
			return true;
		}

		return $this->run(static function () use ($documentIds): void {
			$fullText = \CSearchFullText::getInstance();
			foreach ($documentIds as $documentId)
			{
				$fullText->deleteById($documentId);
			}
		});
	}

	/**
	 * Rows of the entries of the given messages, read by a direct query for the same reason
	 * requeueForSync() reads its own: the connection of the search module is not the connection of the
	 * portal, and CSearchItem::GetList() resolves the url of every row through the handlers of foreign
	 * modules.
	 *
	 * @param int[] $itemIds
	 * @return int[] Identifiers of the rows; an empty list when the index cannot be read, which costs
	 *               nothing more than the state the failure path already leaves behind.
	 */
	private function listDocumentIdsOfMessages(array $itemIds): array
	{
		if (!Loader::includeModule('search'))
		{
			return [];
		}

		try
		{
			$connection = \CDatabase::GetModuleConnection('search');
			$quoted = array_map(
				static fn (int $itemId): string => "'" . $connection->ForSql((string)$itemId) . "'",
				$itemIds,
			);

			$result = $connection->Query(
				"SELECT ID FROM b_search_content WHERE MODULE_ID = '" . self::MODULE_ID . "'"
				. ' AND ITEM_ID IN (' . implode(',', $quoted) . ')'
			);

			$documentIds = [];
			while ($row = $result->Fetch())
			{
				$documentIds[] = (int)$row['ID'];
			}

			return $documentIds;
		}
		catch (\Throwable $exception)
		{
			$this->logThrowable($exception);

			return [];
		}
	}

	/**
	 * A portion of the messages the index holds entries of under the given chat.
	 *
	 * @return int[]|null
	 */
	public function listMessageIdsOfChat(int $chatId, int $limit): ?array
	{
		return $this->listMessageIds(['=PARAM1' => $chatId], $limit);
	}

	/**
	 * A portion of the entries of a chat whose access key stands below the boundary: what a member has
	 * cleared out of his history and what was then taken out of the database along with it. The key is
	 * of a fixed width, so the order of the strings is the order of the messages.
	 *
	 * @return int[]|null
	 */
	public function listMessageIdsBelow(int $chatId, string $boundaryKey, int $limit): ?array
	{
		return $this->listMessageIds([
			'=PARAM1' => $chatId,
			'<PARAM2' => $boundaryKey,
		], $limit);
	}

	/**
	 * A portion of the comments of one post of a channel: they live on the channel and are addressed
	 * by the key of the post, so their own chat finds none of them.
	 *
	 * @return int[]|null
	 */
	public function listMessageIdsOfPost(int $channelChatId, string $postKey, int $limit): ?array
	{
		return $this->listMessageIds([
			'=PARAM1' => $channelChatId,
			'=PARAM2' => $postKey,
		], $limit);
	}

	/**
	 * A portion of what the user authored anywhere on the portal, group chats and channels included.
	 *
	 * @return int[]|null
	 */
	public function listMessageIdsOfAuthor(int $userId, int $limit): ?array
	{
		return $this->listMessageIds([
			'=USER_ID' => $userId,
			'=ENTITY_ID' => $userId,
			'=ENTITY_TYPE_ID' => self::AUTHOR_ENTITY_TYPE_ID,
		], $limit);
	}

	/**
	 * The unit of work of a deletion too large for one filter: the core takes an entry off one at a
	 * time, so the caller reads a portion of identifiers, removes exactly those and comes back.
	 *
	 * No order and no cursor on purpose: neither address of a deletion continues into ITEM_ID in any
	 * index of the table, so an order by it would sort the whole slice on every portion. The caller
	 * removes the very entries it has just read, so the next portion is what is left of the address.
	 *
	 * The filter is expanded by the very helper the core expands it with, so that what is read and what
	 * is deleted may not disagree. The query goes to the connection of the search module: on a portal
	 * with a database of its own for the search the connection of im would reach another one.
	 *
	 * @return int[]|null Null when the index cannot be read: an empty portion means the address holds
	 *                    nothing more, and a failure may not be mistaken for that.
	 */
	private function listMessageIds(array $filter, int $limit): ?array
	{
		if (!Loader::includeModule('search'))
		{
			return null;
		}

		try
		{
			$connection = \CDatabase::GetModuleConnection('search');
			$filter['MODULE_ID'] = self::MODULE_ID;
			$areSitesJoined = false;
			$where = \CSearch::__PrepareFilter($filter, $areSitesJoined);

			$sql = $connection->TopSql(
				'SELECT sc.ITEM_ID FROM b_search_content sc '
				. ($areSitesJoined ? 'INNER JOIN b_search_content_site scsite ON sc.ID = scsite.SEARCH_CONTENT_ID ' : '')
				. ($where !== '' ? 'WHERE ' . $where : ''),
				max(1, $limit),
			);

			$result = $connection->Query($sql);

			$messageIds = [];
			while ($row = $result->Fetch())
			{
				$messageIds[] = (int)$row['ITEM_ID'];
			}

			return $messageIds;
		}
		catch (\Throwable $exception)
		{
			$this->logThrowable($exception);

			return null;
		}
	}

	public function addPermission(string $code, array $filter): bool
	{
		$this->measurePermissionOperation('addPermission', $filter);

		return $this->run(static fn () => \CSearch::AddPermission(self::MODULE_ID, $code, $filter));
	}

	public function deletePermission(string $code, array $filter): bool
	{
		$this->measurePermissionOperation('deletePermission', $filter);

		return $this->run(static fn () => \CSearch::DeletePermission(self::MODULE_ID, $code, $filter));
	}

	/**
	 * Hands the change of the rights to the engine once more, whatever the SQL of the change did.
	 *
	 * The core tells the engine of a change only while its own SQL touched a row, so a delivery that
	 * threw is lost the moment the change is repeated: the repeat writes nothing, answers success, and
	 * the job carrying it leaves the queue with the engine still holding the old rights. Every path
	 * that repeats a refused command of the rights calls this after the command itself.
	 *
	 * On an engine that keeps no rights of its own the call costs nothing: there the method of the core
	 * is empty.
	 */
	public function resyncPermission(string $code, array $filter): bool
	{
		return $this->run(static function () use ($code, $filter): void {
			$filter['MODULE_ID'] = self::MODULE_ID;
			\CSearchFullText::getInstance()->syncPermission($code, $filter);
		});
	}

	/**
	 * One window of the raw output. The engine object never leaves the adapter: what comes back is
	 * already the part of an entry the facade reads, plus the distance the window travelled.
	 *
	 * The distance is measured on the hits of the engine and not on the records handed over: the core
	 * skips a hit whose row of b_search_content is gone - the deferred deletion of the module leaves
	 * such hits behind, and so does an engine that refused a deletion - and it does so by calling
	 * Fetch() again, which hides the skip from the caller altogether.
	 *
	 * @return RawWindow|null Null when the search path is unavailable or failed; a window of no
	 *                        positions is a valid answer meaning the raw output has nothing more.
	 */
	public function searchMessages(MessageSearchQuery $query, string $siteId, int $offset, int $size): ?RawWindow
	{
		if (!Loader::includeModule('search'))
		{
			return null;
		}

		try
		{
			$search = new \CSearch();
			$search->SetOffset($offset);
			$search->SetLimit($size);
			$search->Search(
				[
					'QUERY' => $query->query,
					'SITE_ID' => $siteId,
					'MODULE_ID' => self::MODULE_ID,
				],
				$query->sort->toSearchSort(),
			);

			// a broken cluster answers with an empty result and a non-zero errorno instead of throwing,
			// so without this check it would look exactly like a successful empty output
			if ((int)$search->errorno > 0)
			{
				(new Logger(self::READ_LOG_TAG))->logArray([
					'type' => 'searchFailure',
					'errorno' => (int)$search->errorno,
					'error' => (string)$search->error,
				]);

				return null;
			}

			// the hits of the window as the engine ordered them, read before the walk: a record is put
			// back on its own position by the row it was written under, so a skipped hit shifts nothing
			$hits = is_array($search->arResult) ? array_values($search->arResult) : [];
			$positionOfRow = [];
			foreach ($hits as $position => $hit)
			{
				$rowId = (int)($hit['ID'] ?? 0);
				if ($rowId > 0)
				{
					$positionOfRow[$rowId] = $position;
				}
			}

			$records = [];
			$delivered = 0;
			while ($row = $search->Fetch())
			{
				// a path without hits of its own - the plain SQL search - skips nothing, so the order of
				// delivery is the order of the positions
				$position = $positionOfRow[(int)($row['ID'] ?? 0)] ?? $delivered;
				$records[$position] = $this->buildRecord($row);
				$delivered++;
			}

			return new RawWindow($records, max(count($hits), $delivered));
		}
		catch (\Throwable $exception)
		{
			$this->logThrowable($exception);

			return null;
		}
	}

	private function buildRecord(array $row): SearchRecord
	{
		return new SearchRecord(
			(int)($row['ITEM_ID'] ?? 0),
			(int)($row['USER_ID'] ?? 0),
			$this->buildSnippet($row),
		);
	}

	/**
	 * @return SnippetSegment[]
	 */
	private function buildSnippet(array $row): array
	{
		$bodyFormated = (string)($row['BODY_FORMATED'] ?? '');
		if ($bodyFormated !== '')
		{
			return $this->snippetParser->parse($bodyFormated);
		}

		$body = (string)($row['BODY'] ?? '');
		if (trim($body) === '')
		{
			return [];
		}

		// safety net: the core builds BODY_FORMATED on its own, and an empty card on the path is not allowed
		(new Logger(self::READ_LOG_TAG))->log(
			'BODY_FORMATED is missing while BODY is not empty, entry ' . (int)($row['ID'] ?? 0)
		);

		return [new SnippetSegment($this->cutBody($body), false)];
	}

	private function cutBody(string $body): string
	{
		$maxBodySize = (int)Option::get('search', 'max_body_size', self::DEFAULT_MAX_BODY_SIZE);

		return $maxBodySize > 0 ? mb_substr($body, 0, $maxBodySize) : $body;
	}

	/**
	 * Number of entries a permission command is about to walk, counted here and nowhere else: the core
	 * returns nothing from its permission calls, and the counter of the connection is already spoiled
	 * by the message the engine puts in its sync queue.
	 *
	 * The unit reported is an operation of the index service and not a single command: members added at
	 * once share one filter. The threshold is kept in the record because the line of the log is written
	 * after the hit, and the two may not read the option apart.
	 */
	private function measurePermissionOperation(string $operation, array $filter): void
	{
		$key = self::buildFilterKey($filter);
		if (isset($this->permissionOperations[$key]))
		{
			$this->permissionOperations[$key]['commands']++;

			return;
		}

		// an operation nothing measured is never reported, and the commands counted under it would be
		// written nowhere: with the budget spent the diagnostics costs the key of the filter and no more
		if ($this->measurementsLeft <= 0 || !Loader::includeModule('search'))
		{
			return;
		}

		$threshold = $this->getPermissionLogThreshold();
		$this->measurementsLeft--;

		$this->permissionOperations[$key] = [
			'operation' => $operation,
			'chatId' => (int)($filter['=PARAM1'] ?? 0),
			'boundary' => isset($filter['>=PARAM2']) ? (string)$filter['>=PARAM2'] : null,
			'entries' => $this->countEntries($filter, $threshold),
			'threshold' => $threshold,
			'commands' => 1,
			'logged' => false,
		];
		$this->unreportedPermissionOperations[$key] = true;

		$this->schedulePermissionLog();
	}

	/**
	 * Entries under the filter, counted no further than one past the threshold: the line of the log
	 * asks whether the walk is larger than the threshold and never how large it is, while an exact
	 * count would read the whole chat before every command of the rights.
	 *
	 * The filter is expanded by the very helper the core expands it with, so that the count and the
	 * command may not disagree. The query goes to the connection of the search module: on a portal with
	 * a database of its own the connection of im would reach another one.
	 */
	private function countEntries(array $filter, int $threshold): int
	{
		try
		{
			$connection = \CDatabase::GetModuleConnection('search');
			$filter['MODULE_ID'] = self::MODULE_ID;
			$areSitesJoined = false;
			$where = \CSearch::__PrepareFilter($filter, $areSitesJoined);

			$bounded = $connection->TopSql(
				'SELECT 1 FROM b_search_content sc '
				. ($areSitesJoined ? 'INNER JOIN b_search_content_site scsite ON sc.ID = scsite.SEARCH_CONTENT_ID ' : '')
				. ($where !== '' ? 'WHERE ' . $where : ''),
				max(1, $threshold + 1),
			);

			$row = $connection->Query('SELECT COUNT(*) AS CNT FROM (' . $bounded . ') sub')->Fetch();

			return (int)($row['CNT'] ?? 0);
		}
		catch (\Throwable $exception)
		{
			$this->logThrowable($exception);

			// zero keeps the failed count memoized: a broken count may not be retried on every command
			return 0;
		}
	}

	private static function getMeasurementBudget(): int
	{
		return max(0, (int)Option::get(
			self::MODULE_ID,
			self::PERMISSION_MEASUREMENT_BUDGET_OPTION,
			self::DEFAULT_PERMISSION_MEASUREMENT_BUDGET,
		));
	}

	private function getPermissionLogThreshold(): int
	{
		return (int)Option::get(
			self::MODULE_ID,
			self::PERMISSION_LOG_THRESHOLD_OPTION,
			self::DEFAULT_PERMISSION_LOG_THRESHOLD,
		);
	}

	private function schedulePermissionLog(): void
	{
		if ($this->isPermissionLogScheduled)
		{
			return;
		}

		$this->isPermissionLogScheduled = true;
		Application::getInstance()->addBackgroundJob(fn () => $this->logPermissionOperations());
	}

	/**
	 * Written after the hit, when the number of commands that went under a filter is finally known.
	 * This is the only signal 'a large walk has been queued' the feature has, and what it reports of
	 * the size is what the bounded count knows: entries under the filter, not rows of rights changed.
	 */
	private function logPermissionOperations(): void
	{
		$this->isPermissionLogScheduled = false;

		$unreported = $this->unreportedPermissionOperations;
		$this->unreportedPermissionOperations = [];

		$logger = new Logger(self::PERMISSION_LOG_TAG);

		foreach (array_keys($unreported) as $key)
		{
			$record = $this->permissionOperations[$key];
			if ($record['entries'] <= $record['threshold'])
			{
				continue;
			}

			$this->permissionOperations[$key]['logged'] = true;

			$logger->logArray([
				'type' => 'largePermissionOperation',
				'operation' => $record['operation'],
				'chatId' => $record['chatId'],
				'boundary' => $record['boundary'],
				'entries' => '> ' . $record['threshold'],
				'commands' => $record['commands'],
			]);
		}
	}

	/**
	 * The code of the right is deliberately left out: it does not change how many entries the filter
	 * covers, and adding it would count the very same walk once per member.
	 */
	private static function buildFilterKey(array $filter): string
	{
		ksort($filter);

		return serialize($filter);
	}

	/**
	 * The dates of an entry are server time whoever is authorized while it is written. Without this the
	 * two roads of the core disagree: a first write stores the DATE_CHANGE string as it is, while a
	 * rewrite goes through CDatabase::PrepareUpdate() and CharToDateFunction(), which reads the string
	 * as the local time of the current user and subtracts his offset.
	 *
	 * @return int|false Identifier of the row of the entry; zero or false when the core wrote none.
	 */
	private static function indexInServerTime(int $messageId, array $entry, bool $overwrite)
	{
		\CTimeZone::Disable();

		try
		{
			return \CSearch::Index(self::MODULE_ID, $messageId, $entry, $overwrite);
		}
		finally
		{
			\CTimeZone::Enable();
		}
	}

	/**
	 * Protected and not private for the same reason IndexService::indexStoredMessage() is: a case has to
	 * be able to answer as the engine refusing a deletion does, while everything around it - the reading
	 * of the documents and the queueing of what was left behind - runs for real.
	 */
	protected function deleteByFilter(array $filter): bool
	{
		return $this->run(static fn () => \CSearch::DeleteIndex(self::MODULE_ID, $filter));
	}

	/**
	 * @param callable|null $onFailure Recovery of the failed call, run after the throwable is logged.
	 */
	private function run(callable $operation, ?callable $onFailure = null): bool
	{
		if (!Loader::includeModule('search'))
		{
			return false;
		}

		try
		{
			$operation();
		}
		catch (\Throwable $exception)
		{
			$this->logThrowable($exception);

			if ($onFailure !== null)
			{
				$onFailure();
			}

			return false;
		}

		return true;
	}

	/**
	 * Puts an entry the engine failed on into the catching up queue of the search module.
	 *
	 * With the delay mode of the module switched off, CSearch::Index() writes the SQL row, the rights
	 * and the site before it calls the engine, so an entry the engine threw on is SQL-only and shows up
	 * in no output until something rewrites it. The receiver of that queue calls update() with ID,
	 * MODULE_ID and ITEM_ID, which the OpenSearch adapter answers by a full replace().
	 *
	 * On an engine without a deferred synchronisation syncContent() answers false: this shortens the
	 * window of a manual intervention and does not replace the catching up walk of the stepper.
	 *
	 * The row is read by a direct query and not through CSearchItem::GetList(): that one goes through
	 * the default connection of the portal, which on a portal with a database of its own for the search
	 * would be the wrong one, and its Fetch() resolves the url of every row through the OnSearchGetURL
	 * handlers of foreign modules, which a failure path may not be firing.
	 */
	private function requeueForSync(int $messageId): void
	{
		try
		{
			$connection = \CDatabase::GetModuleConnection('search');
			$row = $connection->Query(
				"SELECT ID FROM b_search_content WHERE MODULE_ID = '" . self::MODULE_ID . "'"
				. " AND ITEM_ID = '" . $connection->ForSql((string)$messageId) . "'"
			)->Fetch();

			$id = (int)($row['ID'] ?? 0);
			if ($id > 0)
			{
				\CSearchFullText::getInstance()->syncContent($id);
			}
		}
		catch (\Throwable $exception)
		{
			$this->logThrowable($exception);
		}
	}

	private function logThrowable(\Throwable $exception): void
	{
		(new Logger(self::LOG_TAG))->logThrowable($exception);
	}
}
