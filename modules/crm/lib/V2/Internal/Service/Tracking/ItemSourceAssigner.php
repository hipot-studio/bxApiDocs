<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Trace;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceRebindConflictException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingSourceInvalidException;
use Bitrix\Crm\V2\Internal\Repository\Tracking\SourceRepository;
use Bitrix\Crm\V2\Internal\Repository\Tracking\TraceRepository;
use Bitrix\Crm\V2\Internal\Repository\Tracking\TraceWriteRepository;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * Assigns the source of an item: the source of the trace of its last binding.
 *
 * The change touches this item only. The trace of the item is left as it is: the item is moved to a copy
 * of it with the new source, and the other items keep their attribution. The copy is dated with the
 * current moment, so the reading of legacy, which takes the newest trace as the current one, shows the
 * assigned source as current too.
 *
 * Rights are not checked here: the caller has checked the right to change the item.
 *
 * @internal
 */
final class ItemSourceAssigner
{
	private const REBIND_ATTEMPTS = 2;

	public function __construct(
		private readonly TraceRepository $traces = new TraceRepository(),
		private readonly TraceWriteRepository $traceWriter = new TraceWriteRepository(),
		private readonly SourceRepository $sources = new SourceRepository(),
	)
	{
	}

	/**
	 * @param int|null $sourceId null takes the source off and is always assignable
	 * @throws TrackingSourceInvalidException the source does not exist or is archived
	 */
	public function assertAssignable(?int $sourceId): void
	{
		if ($sourceId !== null && !$this->sources->isActive($sourceId))
		{
			throw new TrackingSourceInvalidException($sourceId);
		}
	}

	/**
	 * Whether the source is the current source of the item already, archived or not, for both readings: the
	 * trace of the last binding has this source and is the newest trace of the item by date as well, so the
	 * reading of legacy shows the same source. Otherwise an assignment is due even with the same source: it
	 * dates the trace anew. Null stands for an item without a source.
	 */
	public function isCurrent(ItemIdentifier $item, ?int $sourceId): bool
	{
		$entityTypeId = $item->getEntityTypeId();
		$entityId = $item->getEntityId();
		$currentSourceId = $this->traces->getCurrentSourceIds($entityTypeId, [$entityId])[$entityId] ?? null;

		return $currentSourceId === $sourceId && $this->traces->isLastBoundTraceNewest($entityTypeId, $entityId);
	}

	/**
	 * The source is not checked here, see {@see assertAssignable()}.
	 *
	 * The trace of the item is never changed: the item is moved to a copy of it with the new source, dated
	 * with the current moment, whether the trace is shared or not. No binding of another item, committed by
	 * a parallel request at any moment, can get the source this way; the old trace stays as it was, bound
	 * to the other items or to none, the way legacy leaves unused traces.
	 *
	 * The binding is moved to the copy only while it still points at the trace that was read; a binding
	 * moved by a parallel request in between leaves the copy unused, the copy is deleted and the
	 * attribution is read once more. A second miss is a conflict. Each attempt runs in one transaction of
	 * the database, so a refusal of the storage leaves no copy behind.
	 *
	 * @param int|null $sourceId null takes the source off: the copy has no source
	 * @throws TraceRebindConflictException the binding of the item kept changing under parallel writes
	 * @throws SystemException the storage refused a write
	 */
	public function assign(ItemIdentifier $item, ?int $sourceId): void
	{
		$sourceId ??= Trace::NO_SOURCE;

		for ($attempt = 0; $attempt < self::REBIND_ATTEMPTS; $attempt++)
		{
			if (TraceTransaction::run(fn(): bool => $this->tryAssign($item, $sourceId)))
			{
				return;
			}
		}

		throw new TraceRebindConflictException();
	}

	/**
	 * @return bool false when the binding of the item was moved by a parallel request
	 */
	private function tryAssign(ItemIdentifier $item, int $sourceId): bool
	{
		$now = new DateTime();
		$binding = $this->traces->getLastBinding($item->getEntityTypeId(), $item->getEntityId());
		if ($binding === null)
		{
			$this->createTrace($item, $sourceId, $now);

			return true;
		}

		$traceId = $binding['traceId'];
		$copyId = $this->traceWriter->copyWithSource($traceId, $sourceId, $now);
		if ($this->traceWriter->rebindIfUnchanged($binding['bindingId'], $traceId, $copyId))
		{
			return true;
		}

		$this->traceWriter->delete($copyId);

		return false;
	}

	private function createTrace(ItemIdentifier $item, int $sourceId, DateTime $now): void
	{
		if ($sourceId === Trace::NO_SOURCE)
		{
			return;
		}

		$traceId = $this->traceWriter->create(new Trace(null, $sourceId, $now, false));
		$this->traceWriter->bind($traceId, $item);
	}
}
