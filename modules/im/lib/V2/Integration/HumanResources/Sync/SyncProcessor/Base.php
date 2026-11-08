<?php

namespace Bitrix\Im\V2\Integration\HumanResources\Sync\SyncProcessor;

use Bitrix\HumanResources\Repository\NodeRelationRepository;
use Bitrix\HumanResources\Service\NodeMemberService;
use Bitrix\HumanResources\Service\NodeService;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Service\NodeRelationService;
use Bitrix\Im\Model\HrSyncQueueTable;
use Bitrix\Im\V2\Integration\HumanResources\Sync\Item\EntityType;
use Bitrix\Im\V2\Integration\HumanResources\Sync\Item\QueueItem;
use Bitrix\Im\V2\Integration\HumanResources\Sync\Item\Status;
use Bitrix\Im\V2\Integration\HumanResources\Sync\Item\SyncInfo;
use Bitrix\Im\V2\Integration\HumanResources\Sync\Result\QueueItemResult;
use Bitrix\Im\V2\Integration\HumanResources\Sync\SyncProcessor;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

abstract class Base implements SyncProcessor
{
	protected const LOCK_TTL = 1;
	protected const LOCK_NAME = 'im_hr_sync';
	protected const RUN_LOCK_NAME = 'im_hr_sync_run';
	protected const LOCKED_ITEM_RETURN_DELAY = 300;
	protected const OVERDUE_ITEM_RETURN_FACTOR = 4;
	protected const CANDIDATES_PER_PAGE = 10;
	protected const QUEUE_CURSOR_TTL = 3600;

	abstract protected function getEntityType(): EntityType;

	protected NodeMemberService $memberService;
	protected NodeRelationService $relationService;
	protected NodeRelationRepository $relationRepository;
	protected NodeService $nodeService;

	/**
	 * Contexts of the candidates returned by age, by candidate key, kept until ownership is settled.
	 * @var array<string, array>
	 */
	private array $pendingRecoveryLogs = [];

	public function __construct()
	{
		Loader::includeModule('humanresources');

		$this->memberService = Container::getNodeMemberService();
		$this->relationService = Container::getNodeRelationService();
		$this->relationRepository = Container::getNodeRelationRepository();
		$this->nodeService = Container::getNodeService();
	}

	public static function getInstance(EntityType $entityType): static
	{
		return match ($entityType)
		{
			EntityType::CHAT => new Chat(),
			EntityType::USER => new User(),
		};
	}

	/**
	 * One page of candidates, oldest first, keyed by the id of the queue row - the key is the cursor the
	 * caller pages with. A candidate may belong to a live owner, and answering with one row would let one
	 * long iteration hold up everything behind it, so the caller gets a page and walks past what it
	 * cannot own instead of meeting the same rows every five seconds.
	 *
	 * With nothing to take at four return delays there is nothing for recovery to yield to, so such a
	 * page asks once more by one delay - a wider question, and the only other one it costs.
	 *
	 * @param int $afterId id of the last candidate of the previous page, 0 for the first page
	 * @return array<int, SyncInfo>
	 */
	public function dequeueCandidates(int $afterId = 0): array
	{
		$this->pendingRecoveryLogs = [];
		$threshold = static::getThreshold(static::getOverdueItemReturnDelay());
		$rows = $this->getCandidatesQuery($afterId, $threshold)->fetchAll();

		if (empty($rows))
		{
			return $this->dequeueStuckItems($afterId);
		}

		return $this->toCandidates($rows, $threshold);
	}

	/**
	 * @param array[] $rows
	 * @return array<int, SyncInfo>
	 */
	private function toCandidates(array $rows, DateTime $threshold): array
	{
		$candidates = [];

		foreach ($rows as $row)
		{
			$syncInfo = SyncInfo::createFromRow($row);
			$candidates[(int)$row['ID']] = $syncInfo;

			if ($row['IS_LOCKED'] === 'Y')
			{
				$this->rememberRecoveryOf($syncInfo, $row, $threshold);
			}
		}

		return $candidates;
	}

	/**
	 * Free rows and rows whose owner is overdue, in one walk. Fresh work still has priority over
	 * recovery, but not for ever: a tick takes a single item, so behind "no ordinary work left" a row
	 * locked by a process that died would wait out the whole inflow of the queue. Past several return
	 * delays it joins the ordinary candidates and the order by id decides as usual - whether its owner
	 * is really gone is answered by ownership, not by age.
	 *
	 * The two states are one condition and not a query each because the queue carries no index over
	 * IS_LOCKED: this way the read is a walk of the primary key in the order of the page and stops at the
	 * tenth row it needs, while a query for the locked state alone has nothing to stop it early.
	 */
	protected function getCandidatesQuery(int $afterId, DateTime $threshold): Query
	{
		return $this->getQueueQuery($afterId)->where(
			Query::filter()
				->logic('or')
				->where('IS_LOCKED', false)
				->where(
					Query::filter()
						->where('IS_LOCKED', true)
						->where('DATE_UPDATE', '<', $threshold)
				)
		);
	}

	/**
	 * Candidates of this entity type, oldest first, past the cursor. The cursor is a keyset one - a plain
	 * `ID >` on the ordering column - so the filter stays the same shape however far the walk goes.
	 */
	protected function getQueueQuery(int $afterId = 0): Query
	{
		$query = HrSyncQueueTable::query()
			->setSelect(['*'])
			->where('ENTITY_TYPE', $this->getEntityType()->value)
			->setOrder(['ID'])
			->setLimit(static::CANDIDATES_PER_PAGE)
		;

		if ($afterId > 0)
		{
			$query->where('ID', '>', $afterId);
		}

		return $query;
	}

	/**
	 * A process killed mid-iteration leaves its item locked: nothing clears the flag on the way out, and
	 * without age the item would stay invisible forever. Asked by one return delay instead of four, and
	 * only when the page above found nothing at all.
	 *
	 * @return array<int, SyncInfo>
	 */
	protected function dequeueStuckItems(int $afterId = 0): array
	{
		$threshold = static::getThreshold(static::getLockedItemReturnDelay());

		return $this->toCandidates($this->getCandidatesQuery($afterId, $threshold)->fetchAll(), $threshold);
	}

	private function rememberRecoveryOf(SyncInfo $syncInfo, array $row, DateTime $threshold): void
	{
		$this->pendingRecoveryLogs[$this->getCandidateKey($syncInfo)] = [
			'itemId' => (int)$row['ID'],
			'entityType' => $this->getEntityType()->value,
			'dateUpdate' => (string)$row['DATE_UPDATE'],
			'threshold' => (string)$threshold,
		];
	}

	/**
	 * A candidate returned by age is a suspicion, not a recovery, so the warning waits for the caller to
	 * take ownership. Written at selection time it would report every live iteration longer than the
	 * delay as recovered, once per skipped candidate on every tick.
	 */
	public function logRecoveryOf(SyncInfo $syncInfo): void
	{
		$key = $this->getCandidateKey($syncInfo);
		$pending = $this->pendingRecoveryLogs[$key] ?? null;
		unset($this->pendingRecoveryLogs[$key]);

		if ($pending === null)
		{
			return;
		}

		$this->writeRecoveryWarning($pending);
	}

	protected function writeRecoveryWarning(array $context): void
	{
		(new LoggerFactory())->createById('im.HumanResources.StuckQueueItem')?->warning(
			'Returned a stuck sync queue item to processing',
			$context,
		);
	}

	/**
	 * The queue holds at most one row per triple, so the triple below identifies a candidate - and it is
	 * the same triple the run lock is named after.
	 */
	private function getCandidateKey(SyncInfo $syncInfo): string
	{
		return $syncInfo->entityType->value . ':' . $syncInfo->entityId . ':' . $syncInfo->nodeId;
	}

	/**
	 * Where the next tick starts looking. The walk of one tick is bounded, so without carrying the
	 * position over a head longer than the budget would never be passed.
	 *
	 * Kept in the managed cache and not in a module option: the position is rewritten every five
	 * seconds, while an option write costs a row in b_option, a reset of the module's whole option cache
	 * and two events - paid by every request of the portal. A lost position costs one walk from the head
	 * of the queue and never an answer.
	 */
	public function getQueueCursor(): int
	{
		$cache = Application::getInstance()->getManagedCache();
		$cacheId = $this->getQueueCursorCacheId();

		if (!$cache->read(static::QUEUE_CURSOR_TTL, $cacheId))
		{
			return 0;
		}

		return (int)$cache->get($cacheId);
	}

	/**
	 * Kept only while a tick has something left to walk past: a productive tick puts it back to the head
	 * of the queue.
	 *
	 * The comparison keeps a tick that reached the same place from writing at all, and it is also what
	 * makes the write possible - the managed cache writes only an entry it was asked to read.
	 */
	public function setQueueCursor(int $afterId): void
	{
		if ($afterId === $this->getQueueCursor())
		{
			return;
		}

		Application::getInstance()->getManagedCache()->setImmediate($this->getQueueCursorCacheId(), $afterId);
	}

	private function getQueueCursorCacheId(): string
	{
		return 'im_hr_sync_queue_cursor_' . mb_strtolower($this->getEntityType()->value);
	}

	protected static function getLockedItemReturnDelay(): int
	{
		return (int)Option::get('im', 'hr_sync_locked_item_return_delay', static::LOCKED_ITEM_RETURN_DELAY);
	}

	protected static function getOverdueItemReturnDelay(): int
	{
		return static::getLockedItemReturnDelay() * static::OVERDUE_ITEM_RETURN_FACTOR;
	}

	protected static function getThreshold(int $delay): DateTime
	{
		return (new DateTime())->add('-' . $delay . ' seconds');
	}

	public function getOrCreateWithLock(SyncInfo $syncInfo): QueueItemResult
	{
		$isLocked = $this->lock($syncInfo);

		if (!$isLocked)
		{
			return (new QueueItemResult())->setSkip(true);
		}

		try
		{
			return $this->getOrCreateWithLockInternal($syncInfo);
		}
		finally
		{
			$this->unlock($syncInfo);
		}
	}

	public function tryGetWithLock(SyncInfo $syncInfo): ?QueueItem
	{
		$isLocked = $this->lock($syncInfo);

		if (!$isLocked)
		{
			return null;
		}

		try
		{
			$item = $this->tryGetItem($syncInfo);
			return $item?->lock();
		}
		finally
		{
			$this->unlock($syncInfo);
		}
	}

	public function finalizeSync(QueueItem $item): Result
	{
		HrSyncQueueTable::delete($item->id);

		return new Result();
	}

	protected function getOrCreateWithLockInternal(SyncInfo $syncInfo): QueueItemResult
	{
		$result = new QueueItemResult();
		$item = $this->tryGetItem($syncInfo);

		if ($item === null)
		{
			return $result->setResult($this->createNewQueueItem($syncInfo));
		}

		if ($item->syncInfo->direction === $syncInfo->direction)
		{
			return $result->setResult($item)->setSkip(true);
		}

		$result->setSkip($item->isLocked);
		$this->finalizeSync($item);

		return $result->setResult($this->createNewQueueItem($syncInfo));
	}

	protected function tryGetItem(SyncInfo $syncInfo): ?QueueItem
	{
		$row = HrSyncQueueTable::query()
			->setSelect(['*'])
			->where('ENTITY_TYPE', $syncInfo->entityType->value)
			->where('ENTITY_ID', $syncInfo->entityId)
			->where('NODE_ID', $syncInfo->nodeId)
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		return QueueItem::createFromRow($row);
	}

	protected function createNewQueueItem(SyncInfo $syncInfo): QueueItem
	{
		$id = HrSyncQueueTable::add([
			'ENTITY_TYPE' => $syncInfo->entityType->value,
			'ENTITY_ID' => $syncInfo->entityId,
			'NODE_ID' => $syncInfo->nodeId,
			'DIRECTION' => $syncInfo->direction->value,
			'WITH_CHILD_NODES' => $syncInfo->withChildNodes,
			'POINTER' => 0,
			'IS_LOCKED' => true,
			'DATE_CREATE' => new DateTime(),
			'DATE_UPDATE' => new DateTime(),
		])->getId();

		return new QueueItem(
			$id,
			$syncInfo,
			0,
			true,
			Status::NEW,
		);
	}

	public function hasItemsInQueue(): bool
	{
		$row = HrSyncQueueTable::query()
			->setSelect(['ID'])
			->where('ENTITY_TYPE', $this->getEntityType()->value)
			->setLimit(1)
			->fetch()
		;

		if ($row === false)
		{
			return false;
		}

		return true;
	}

	protected static function getLockName(SyncInfo $syncInfo): string
	{
		return static::LOCK_NAME . "{$syncInfo->entityType->value}_{$syncInfo->entityId}_{$syncInfo->nodeId}";
	}

	protected function lock(SyncInfo $syncInfo): bool
	{
		return Application::getConnection(HrSyncQueueTable::getConnectionName())
			->lock(static::getLockName($syncInfo), self::LOCK_TTL)
		;
	}

	protected function unlock(SyncInfo $syncInfo): bool
	{
		return Application::getConnection(HrSyncQueueTable::getConnectionName())
			->unlock(static::getLockName($syncInfo))
		;
	}

	protected static function getRunLockName(SyncInfo $syncInfo): string
	{
		return static::RUN_LOCK_NAME . "{$syncInfo->entityType->value}_{$syncInfo->entityId}_{$syncInfo->nodeId}";
	}

	/**
	 * Ownership of the iteration itself, as opposed to the short lock guarding the queue row. The
	 * database releases it when the owning connection goes away, so a killed process frees it and a live
	 * one keeps it - that is what separates a stuck item from a slow one. Never waits.
	 *
	 * The guarantee is the driver's: Connection::lock() is a stub reporting success without taking
	 * anything, and only mysql and pgsql - the drivers this product ships - implement it.
	 */
	public function tryLockRun(SyncInfo $syncInfo): bool
	{
		return Application::getConnection(HrSyncQueueTable::getConnectionName())
			->lock(static::getRunLockName($syncInfo))
		;
	}

	public function unlockRun(SyncInfo $syncInfo): bool
	{
		return Application::getConnection(HrSyncQueueTable::getConnectionName())
			->unlock(static::getRunLockName($syncInfo))
		;
	}
}
