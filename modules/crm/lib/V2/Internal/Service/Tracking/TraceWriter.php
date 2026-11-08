<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Trace;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceBindingsChangedException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceTooManyBindingsException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingSourceInvalidException;
use Bitrix\Crm\V2\Internal\Repository\Tracking\ItemUtmRepository;
use Bitrix\Crm\V2\Internal\Repository\Tracking\TraceRepository;
use Bitrix\Crm\V2\Internal\Repository\Tracking\TraceWriteRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * Creates and deletes traces on behalf of a user.
 *
 * Both write the tables of the traces only, so each runs in one transaction of the database: a refusal
 * of the storage leaves nothing half written.
 *
 * The trace is written as it is given. Unlike the saving of legacy, no existing trace is detected and
 * reused, and no previous visit is linked: the caller states the attribution explicitly.
 *
 * @internal
 */
final class TraceWriter
{
	private const DELETE_ATTEMPTS = 2;
	/** The most bindings a trace deleted in one request may have; they are all read and checked at once. */
	public const MAX_DELETE_BINDINGS = 500;

	public function __construct(
		private readonly TraceAccess $access = new TraceAccess(),
		private readonly ItemSourceAssigner $assigner = new ItemSourceAssigner(),
		private readonly TraceRepository $traces = new TraceRepository(),
		private readonly TraceWriteRepository $traceWriter = new TraceWriteRepository(),
		private readonly ItemUtmRepository $itemUtm = new ItemUtmRepository(),
		private readonly int $maxDeleteBindings = self::MAX_DELETE_BINDINGS,
	)
	{
	}

	/**
	 * Stores the trace dated with the current moment and binds it to every item. An item without UTM
	 * tags of its own gets the tags of the trace, the way the binding of a trace in legacy does it.
	 *
	 * @param ItemIdentifier[] $items at least one
	 * @return int the id of the trace
	 * @throws ArgumentException no item is given
	 * @throws TraceAccessDeniedException the user may read but not update one of the items
	 * @throws TraceNotFoundException one of the items does not exist or the user may not read it
	 * @throws TrackingSourceInvalidException the source is absent, does not exist or is archived
	 * @throws SystemException the storage refused a write; nothing is written
	 */
	public function add(int $userId, Trace $trace, array $items): int
	{
		$items = self::unique($items);
		if (empty($items))
		{
			throw new ArgumentException('A trace has to be bound to at least one item', 'items');
		}

		$this->access->assertCanBindItems($userId, $items);
		$this->assigner->assertAssignable($trace->sourceId);

		$trace = $trace->withCreatedTime(new DateTime());
		$tags = TraceWriteRepository::toTags($trace->utm);

		return TraceTransaction::run(function () use ($trace, $items, $tags): int
		{
			$traceId = $this->traceWriter->create($trace);
			$this->traceWriter->bindNew($traceId, $items);
			$this->itemUtm->addFromTags($items, $tags);

			return $traceId;
		});
	}

	/**
	 * Deletes the trace from the attribution of every item; the source of an item falls back to the trace
	 * of its previous binding. Bindings to items that are gone count for nothing.
	 *
	 * @throws TraceNotFoundException the trace does not exist or the user may read none of its items
	 * @throws TraceAccessDeniedException the user may not update one of the existing items
	 * @throws SystemException the storage refused a write; nothing is deleted
	 */
	public function delete(int $userId, int $traceId): void
	{
		if ($userId <= 0 || $traceId <= 0)
		{
			throw new TraceNotFoundException();
		}

		for ($attempt = 0; $attempt < self::DELETE_ATTEMPTS; $attempt++)
		{
			try
			{
				TraceTransaction::run(fn() => $this->deleteCheckedBindings($userId, $traceId));

				return;
			}
			catch (TraceBindingsChangedException)
			{
			}
		}

		throw new SystemException('The bindings of the trace kept changing, the trace was not deleted');
	}

	/**
	 * The bindings are read once, under lock; the rights to read and to update are asked for them, and only
	 * these bindings are deleted: an item bound to the trace by a parallel request after the read does not
	 * lose its binding unchecked - the delete is rolled back and repeated on the bindings as they are now.
	 *
	 * @throws TraceNotFoundException
	 * @throws TraceAccessDeniedException
	 * @throws TraceTooManyBindingsException
	 * @throws TraceBindingsChangedException
	 */
	private function deleteCheckedBindings(int $userId, int $traceId): void
	{
		$bindings = $this->traces->lockBindings($traceId, $this->maxDeleteBindings + 1);
		$items = TraceRepository::itemsOf($bindings);
		if (count($bindings) > $this->maxDeleteBindings)
		{
			// A hidden trace stays a missing one, however many items it has.
			$this->access->assertCanReadTraceOf($userId, $items);

			throw new TraceTooManyBindingsException($this->maxDeleteBindings);
		}

		$this->access->assertCanDeleteTraceOf($userId, $items);

		$this->traceWriter->deleteWithBindings($traceId, array_column($bindings, 'bindingId'));
	}

	/**
	 * @param ItemIdentifier[] $items
	 * @return ItemIdentifier[]
	 */
	private static function unique(array $items): array
	{
		$unique = [];
		foreach ($items as $item)
		{
			$unique[$item->getEntityTypeId() . ':' . $item->getEntityId()] = $item;
		}

		return array_values($unique);
	}
}
