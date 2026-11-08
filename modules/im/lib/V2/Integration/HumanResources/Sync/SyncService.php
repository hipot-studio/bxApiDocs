<?php

namespace Bitrix\Im\V2\Integration\HumanResources\Sync;

use Bitrix\HumanResources\Repository\NodeRelationRepository;
use Bitrix\HumanResources\Service\NodeMemberService;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Item\NodeRelation;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Service\NodeRelationService;
use Bitrix\HumanResources\Type\RelationEntityType;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Tree\Async\UnlinkDepartmentFromDescendantsMessage;
use Bitrix\Im\V2\Chat\Tree\ChatTreeDepartmentSynchronizer;
use Bitrix\Im\V2\Common\PeriodAgentTrait;
use Bitrix\Im\V2\Integration\HumanResources\Sync\Item\EntityType;
use Bitrix\Im\V2\Integration\HumanResources\Sync\SyncProcessor\Base;
use Bitrix\Im\V2\Result;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;

class SyncService
{
	use PeriodAgentTrait;

	protected const AGENT_SHORT_PERIOD = 5;
	protected const AGENT_LONG_PERIOD = 300;
	protected const CANDIDATE_PAGES_PER_TICK = 5;

	protected NodeMemberService $memberService;
	protected NodeRelationService $relationService;
	protected NodeRelationRepository $relationRepository;
	protected SyncProcessor $syncProcessor;
	protected EntityType $entityType;

	public function __construct(EntityType $entityType)
	{
		Loader::requireModule('humanresources');

		$this->entityType = $entityType;
		$this->syncProcessor = Base::getInstance($entityType);
		$this->memberService = Container::getNodeMemberService();
		$this->relationService = Container::getNodeRelationService();
		$this->relationRepository = Container::getNodeRelationRepository();
	}

	protected static function getAgentNameByEntityType(Item\EntityType $entityType): string
	{
		$agentNameByType = [
			Item\EntityType::CHAT->value => '\Bitrix\Im\V2\Integration\HumanResources\Sync\SyncService::syncRelationAgent();',
			Item\EntityType::USER->value => '\Bitrix\Im\V2\Integration\HumanResources\Sync\SyncService::syncMemberAgent();',
		];

		return $agentNameByType[$entityType->value];
	}

	public static function onMemberAdded(Event $event): void
	{
		/** @var NodeMember $member */
		$member = $event->getParameter('member');

		(new static(EntityType::USER))
			->startSync(Item\SyncInfo::createFromNodeMember($member, Item\SyncDirection::ADD))
		;
	}

	public static function onMemberDeleted(Event $event): void
	{
		/** @var NodeMember $member */
		$member = $event->getParameter('member');

		(new static(EntityType::USER))
			->startSync(Item\SyncInfo::createFromNodeMember($member, Item\SyncDirection::DELETE))
		;
	}

	public static function onMemberUpdated(Event $event): void
	{
		/** @var NodeMember $member */
		$member = $event->getParameter('member');
		/** @var NodeMember|null $previousMember */
		$previousMember = $event->getParameter('previousMember');

		if ($previousMember === null)
		{
			return;
		}

		if ($member->nodeId === $previousMember->nodeId)
		{
			return;
		}

		(new static(EntityType::USER))
			->startSync(Item\SyncInfo::createFromNodeMember($member, Item\SyncDirection::ADD))
		;
		(new static(EntityType::USER))
			->startSync(Item\SyncInfo::createFromNodeMember($previousMember, Item\SyncDirection::DELETE))
		;
	}

	public static function onRelationAdded(Event $event): void
	{
		/** @var NodeRelation $relation */
		$relation = $event->getParameter('relation');
		if (
			$relation->entityType !== RelationEntityType::CHAT
			|| $relation->node === null
		)
		{
			return;
		}

		(new static(EntityType::CHAT))
			->startSync(Item\SyncInfo::createFromNodeRelation($relation, Item\SyncDirection::ADD))
		;

		// Mirror the department onto ancestor chats so the tree invariant holds. Order does not matter for
		// correctness: each chat adds its own members through its own (gate-exempt) structure sync. Syncing
		// this chat first also keeps it first in line for the per-request add budget.
		self::syncDepartmentsToAncestors((int)$relation->entityId);
	}

	/**
	 * Maintains the tree invariant: a department/team linked to a chat is mirrored onto its ancestor chats.
	 * This is the single choke-point — every link path (attach cascade, member-edit, Structure::link, create)
	 * goes through NodeRelationRepository::create() and raises this OnRelationAdded(CHAT) event.
	 */
	private static function syncDepartmentsToAncestors(int $chatId): void
	{
		if ($chatId <= 0)
		{
			return;
		}

		$chat = Chat::getInstance($chatId);
		if ((int)$chat->getChatId() <= 0)
		{
			return;
		}

		ServiceLocator::getInstance()
			->get(ChatTreeDepartmentSynchronizer::class)
			->syncToAncestors($chat)
		;
	}

	/**
	 * Down-cascade complement of syncDepartmentsToAncestors: a department unlinked from a chat must be gone
	 * from its descendants too (e.g. socialnetwork drops the department off the project chat on COLLAB
	 * unlink — im propagates it down the tree). Offloaded to a queue and processed batch by batch
	 * (DepartmentTreeSyncReceiver) so a wide/deep tree never blocks the request.
	 */
	private static function enqueueDescendantDepartmentUnlink(int $chatId, int $nodeId): void
	{
		if ($chatId <= 0 || $nodeId <= 0)
		{
			return;
		}

		(new UnlinkDepartmentFromDescendantsMessage($chatId, $nodeId))->sendToQueue();
	}

	public static function onRelationDeleted(Event $event): void
	{
		/** @var NodeRelation $relation */
		$relation = $event->getParameter('relation');
		if ($relation->entityType !== RelationEntityType::CHAT)
		{
			return;
		}

		$syncService = new static(EntityType::CHAT);

		if (Container::getNodeService()->getNodeInformation($relation->nodeId) !== null)
		{
			$syncService->startSync(
				Item\SyncInfo::createFromNodeRelation($relation, Item\SyncDirection::DELETE)
			);

			self::enqueueDescendantDepartmentUnlink((int)$relation->entityId, (int)$relation->nodeId);
		}
		else
		{
			$syncService->startSync(
				Item\SyncInfo::createFromNodeRelation($relation, Item\SyncDirection::NODE_DELETE)
			);
		}
	}

	public static function onRelationPartDeleted(Event $event): void
	{
		/** @var NodeRelation $relation */
		$relation = $event->getParameter('relation');
		if (
			$relation->entityType !== RelationEntityType::CHAT
			|| $relation->node === null
			|| $relation->node->isDepartment()
		)
		{
			return;
		}

		$syncService = new static(EntityType::CHAT);
		$syncService->startSync(
			Item\SyncInfo::createFromNodeRelation($relation, Item\SyncDirection::NODE_DELETE)
		);
	}

	public static function syncRelationAgent(): string
	{
		if (!Loader::includeModule('humanresources'))
		{
			return self::getAgentNameByEntityType(Item\EntityType::CHAT);
		}

		(new static(EntityType::CHAT))->sync();

		return self::getAgentNameByEntityType(Item\EntityType::CHAT);
	}

	public static function syncMemberAgent(): string
	{
		if (!Loader::includeModule('humanresources'))
		{
			return self::getAgentNameByEntityType(Item\EntityType::USER);
		}

		(new static(EntityType::USER))->sync();

		return self::getAgentNameByEntityType(Item\EntityType::USER);
	}

	protected function startSync(Item\SyncInfo $syncInfo): Result
	{
		$itemResult = $this->syncProcessor->getOrCreateWithLock($syncInfo);
		if ($itemResult->skip())
		{
			$this->determinePeriod(false);

			return new Result();
		}

		$item = $itemResult->getResult();

		$firstIterationResult = $this->underRunLock($syncInfo, fn () => $this->runFirstIteration($item));

		if ($firstIterationResult === null)
		{
			// Someone may already be iterating this triple, and doing nothing loses no work: the item
			// goes back to the queue and the agent takes it from there.
			$item->unlock();
			$this->determinePeriod(false);

			return new Result();
		}

		return $firstIterationResult;
	}

	/**
	 * The iteration an event pays for itself, on an item this process owns.
	 */
	private function runFirstIteration(Item\QueueItem $item): Result
	{
		try
		{
			$firstIterationResult = $this->syncProcessor->makeIteration($item);
			if (!$firstIterationResult->hasMore())
			{
				$this->syncProcessor->finalizeSync($item);
			}
			$this->determinePeriod(false);

			return $firstIterationResult;
		}
		catch (\Throwable $exception)
		{
			$item->setErrorStatus();

			return new Result();
		}
		finally
		{
			$item->unlock();
		}
	}

	protected function sync(): Result
	{
		$result = $this->syncNext();
		$this->determinePeriod(true);

		return $result;
	}

	/**
	 * A tick takes the first candidate it can own. A live owner is not work for this process and not a
	 * reason to stop either - one long iteration must not hold up the items behind it - so the tick
	 * walks on, page by page, until it owns something or the queue ends. Without the walk a head held by
	 * owners that outlive their rows would be the whole answer every five seconds.
	 *
	 * The budget bounds the cost of one tick, and the cursor outlives it so that the budget does not
	 * bound the walk. A tick that did find work puts the cursor back to the beginning, so the ordinary
	 * order of the queue is the ordinary case.
	 */
	protected function syncNext(): Result
	{
		$result = new Result();
		$afterId = $this->syncProcessor->getQueueCursor();

		for ($page = 0; $page < static::CANDIDATE_PAGES_PER_TICK; $page++)
		{
			$candidates = $this->syncProcessor->dequeueCandidates($afterId);

			if (empty($candidates))
			{
				// The end of the queue, or a cursor left behind rows that are gone: either way the next
				// tick starts from the head.
				$this->syncProcessor->setQueueCursor(0);

				return $result;
			}

			foreach ($candidates as $syncInfo)
			{
				$iterationResult = $this->underRunLock($syncInfo, function () use ($syncInfo) {
					// Ownership settled what the age of the row could only raise, so a return by age is
					// a fact worth a warning here rather than a suspicion.
					$this->announceRecoveryOf($syncInfo);

					return $this->iterate($syncInfo);
				});

				if ($iterationResult !== null)
				{
					$this->syncProcessor->setQueueCursor(0);

					return $iterationResult;
				}
			}

			$afterId = (int)array_key_last($candidates);
		}

		$this->syncProcessor->setQueueCursor($afterId);

		return $result;
	}

	/**
	 * The announcement is diagnostics and the iteration is the work: left unguarded, a broken logger
	 * would stop the recovery for good, because every later tick picks the same row and fails on the
	 * same warning before reaching the iteration.
	 */
	private function announceRecoveryOf(Item\SyncInfo $syncInfo): void
	{
		try
		{
			$this->syncProcessor->logRecoveryOf($syncInfo);
		}
		catch (\Throwable)
		{
			// There is nowhere left to report a broken logger to.
		}
	}

	/**
	 * Ownership of an iteration and its release in one construct: a run lock leaked by a throw outlives
	 * the request on a persistent connection and then refuses every later attempt at this triple.
	 *
	 * Ownership is taken before the row is read. The item carries the pointer it was read with, so an
	 * item read while the previous owner was still finishing repeats that owner's batch and writes the
	 * old pointer back over the new one.
	 *
	 * @param callable(): Result $work
	 * @return Result|null null when the iteration is owned by someone else
	 */
	private function underRunLock(Item\SyncInfo $syncInfo, callable $work): ?Result
	{
		if (!$this->syncProcessor->tryLockRun($syncInfo))
		{
			return null;
		}

		try
		{
			return $work();
		}
		finally
		{
			$this->syncProcessor->unlockRun($syncInfo);
		}
	}

	/**
	 * One iteration of an item this process already owns. Both writes to the row happen under that
	 * ownership, so a competing agent cannot claim the item while it is being finished.
	 */
	private function iterate(Item\SyncInfo $syncInfo): Result
	{
		$result = new Result();
		$item = $this->syncProcessor->tryGetWithLock($syncInfo);

		if ($item === null)
		{
			return $result;
		}

		try
		{
			if ($this->syncProcessor->makeIteration($item)->hasMore())
			{
				$item->unlock();
			}
			else
			{
				// Under the same guard as the iteration: a failure of the deletion or of the finishing
				// message used to leave the item marked, waiting out the whole return delay.
				$this->syncProcessor->finalizeSync($item);
			}
		}
		catch (\Throwable $exception)
		{
			// The row is freed last, as everywhere: while it is still marked, a competing agent has
			// nothing to claim. Freeing it does not depend on the status write - a failure there would
			// otherwise leave the mark with nobody working on it.
			try
			{
				$item->setErrorStatus();
			}
			finally
			{
				$item->unlock();
			}
		}

		return $result;
	}

	protected static function isAgentPeriodShort(int $newPeriod): bool
	{
		return $newPeriod === self::AGENT_SHORT_PERIOD;
	}

	protected function getPeriodGetter(): callable
	{
		return fn () => $this->syncProcessor->hasItemsInQueue() ? self::AGENT_SHORT_PERIOD : self::AGENT_LONG_PERIOD;
	}

	protected function determinePeriod(bool $fromAgent): void
	{
		self::setPeriodByName($fromAgent, self::getAgentNameByEntityType($this->entityType), $this->getPeriodGetter());
	}
}
