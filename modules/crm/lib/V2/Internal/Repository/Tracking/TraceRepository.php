<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Tracking;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Tracking\Internals\TraceChannelTable;
use Bitrix\Crm\Tracking\Internals\TraceEntityTable;
use Bitrix\Crm\Tracking\Internals\TraceTable;
use Bitrix\Crm\UtmTable;
use Bitrix\Crm\V2\Internal\Entity\Tracking\ChannelCode;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Trace;
use Bitrix\Crm\V2\Internal\Entity\Tracking\TraceChannel;
use Bitrix\Crm\V2\Internal\Entity\Tracking\TracePage;
use Bitrix\Crm\V2\Public\Entity\Item\Utm;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\DateTime;

/**
 * Reads traces and their bindings to items.
 *
 * The order of the bindings is the order of attribution: the binding with the greatest ID is the
 * current one of an item, whatever the dates of the traces say.
 *
 * @internal
 */
final class TraceRepository
{
	private const BATCH_SIZE = 500;

	public function getById(int $traceId): ?Trace
	{
		if ($traceId <= 0)
		{
			return null;
		}

		return $this->loadTraces([$traceId])[$traceId] ?? null;
	}

	/**
	 * Returns the items the trace is bound to, in the order of binding.
	 *
	 * @return ItemIdentifier[]
	 */
	public function getBoundItems(int $traceId): array
	{
		$rows =
			TraceEntityTable::query()
				->setSelect(['ENTITY_TYPE_ID', 'ENTITY_ID'])
				->where('TRACE_ID', $traceId)
				->setOrder(['ID' => 'ASC'])
				->fetchAll()
		;

		$items = [];
		foreach ($rows as $row)
		{
			$entityTypeId = (int)$row['ENTITY_TYPE_ID'];
			$entityId = (int)$row['ENTITY_ID'];
			if ($entityId > 0 && \CCrmOwnerType::isCorrectEntityTypeId($entityTypeId))
			{
				$items[] = new ItemIdentifier($entityTypeId, $entityId);
			}
		}

		return $items;
	}

	/**
	 * Returns a page of the items the trace is bound to, in the order of binding, after the given binding:
	 * a trace bound to many items is read page by page, never at once.
	 *
	 * @return list<array{bindingId: int, item: ItemIdentifier|null}> the item is null for a binding to
	 * an unknown type or a bad id, so the next page still starts after it
	 */
	public function getBoundItemsPage(int $traceId, int $afterBindingId, int $limit): array
	{
		$rows =
			TraceEntityTable::query()
				->setSelect(['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID'])
				->where('TRACE_ID', $traceId)
				->where('ID', '>', $afterBindingId)
				->setOrder(['ID' => 'ASC'])
				->setLimit($limit)
				->fetchAll()
		;

		$page = [];
		foreach ($rows as $row)
		{
			$entityTypeId = (int)$row['ENTITY_TYPE_ID'];
			$entityId = (int)$row['ENTITY_ID'];
			$isValid = $entityId > 0 && \CCrmOwnerType::isCorrectEntityTypeId($entityTypeId);
			$page[] = [
				'bindingId' => (int)$row['ID'],
				'item' => $isValid ? new ItemIdentifier($entityTypeId, $entityId) : null,
			];
		}

		return $page;
	}

	/**
	 * Reads the bindings of the trace with a locking read, in the order of binding: until the transaction
	 * the caller has started ends, they cannot change, and no item can be bound to the trace. Out of a
	 * transaction the lock ends with the read.
	 *
	 * @param int|null $limit the most bindings to read, the first ones in the order of binding
	 * @return list<array{bindingId: int, entityTypeId: int, entityId: int}>
	 */
	public function lockBindings(int $traceId, ?int $limit = null): array
	{
		$entity = TraceEntityTable::getEntity();
		$connection = $entity->getConnection();
		$sqlHelper = $connection->getSqlHelper();

		// The ORM query has no locking read; the index of TRACE_ID keeps the lock on this trace only.
		$rows = $connection->query(sprintf(
			'SELECT %s, %s, %s FROM %s WHERE %s = %d ORDER BY %s%s FOR UPDATE',
			$sqlHelper->quote('ID'),
			$sqlHelper->quote('ENTITY_TYPE_ID'),
			$sqlHelper->quote('ENTITY_ID'),
			$sqlHelper->quote($entity->getDBTableName()),
			$sqlHelper->quote('TRACE_ID'),
			$traceId,
			$sqlHelper->quote('ID'),
			$limit === null ? '' : sprintf(' LIMIT %d', max(0, $limit)),
		))->fetchAll();

		return array_map(
			static fn(array $row): array => [
				'bindingId' => (int)$row['ID'],
				'entityTypeId' => (int)$row['ENTITY_TYPE_ID'],
				'entityId' => (int)$row['ENTITY_ID'],
			],
			$rows,
		);
	}

	/**
	 * The items of the bindings of {@see lockBindings()}, filtered the way {@see getBoundItems()} does it.
	 *
	 * @param list<array{bindingId: int, entityTypeId: int, entityId: int}> $bindings
	 * @return ItemIdentifier[]
	 */
	public static function itemsOf(array $bindings): array
	{
		$items = [];
		foreach ($bindings as $binding)
		{
			if ($binding['entityId'] > 0 && \CCrmOwnerType::isCorrectEntityTypeId($binding['entityTypeId']))
			{
				$items[] = new ItemIdentifier($binding['entityTypeId'], $binding['entityId']);
			}
		}

		return $items;
	}

	/**
	 * Returns a page of the traces of the item, the latest binding first.
	 *
	 * @return Trace[]
	 */
	public function listByItem(int $entityTypeId, int $entityId, int $limit, int $offset): array
	{
		if ($limit <= 0 || $offset < 0)
		{
			return [];
		}

		$traceIds =
			TraceEntityTable::query()
				->setSelect(['TRACE_ID'])
				->where('ENTITY_TYPE_ID', $entityTypeId)
				->where('ENTITY_ID', $entityId)
				->setOrder(['ID' => 'DESC'])
				->setLimit($limit)
				->setOffset($offset)
				->fetchAll()
		;
		$traceIds = array_map('intval', array_column($traceIds, 'TRACE_ID'));
		$traces = $this->loadTraces(array_unique($traceIds));

		$list = [];
		foreach ($traceIds as $traceId)
		{
			if (isset($traces[$traceId]))
			{
				$list[] = $traces[$traceId];
			}
		}

		return $list;
	}

	/**
	 * Returns the current binding of the item.
	 *
	 * @return array{bindingId: int, traceId: int}|null
	 */
	public function getLastBinding(int $entityTypeId, int $entityId): ?array
	{
		$row =
			TraceEntityTable::query()
				->setSelect(['ID', 'TRACE_ID'])
				->where('ENTITY_TYPE_ID', $entityTypeId)
				->where('ENTITY_ID', $entityId)
				->setOrder(['ID' => 'DESC'])
				->setLimit(1)
				->fetch()
		;

		if (!$row)
		{
			return null;
		}

		return ['bindingId' => (int)$row['ID'], 'traceId' => (int)$row['TRACE_ID']];
	}

	/**
	 * Returns the source of the trace of the current binding of every item, archived sources included;
	 * null for an item without a trace or with a trace without a source.
	 *
	 * @param int[] $ids
	 * @return array<int, int|null> item id => source id
	 */
	public function getCurrentSourceIds(int $entityTypeId, array $ids): array
	{
		$ids = array_values(array_unique(array_map('intval', $ids)));
		$sourceIds = array_fill_keys($ids, null);

		foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk)
		{
			$lastBindingIds =
				TraceEntityTable::query()
					->setSelect(['ENTITY_ID', 'LAST_BINDING_ID'])
					->registerRuntimeField(new ExpressionField('LAST_BINDING_ID', 'MAX(%s)', 'ID'))
					->where('ENTITY_TYPE_ID', $entityTypeId)
					->whereIn('ENTITY_ID', $chunk)
					->setGroup(['ENTITY_ID'])
					->fetchAll()
			;
			if (empty($lastBindingIds))
			{
				continue;
			}

			$rows =
				TraceEntityTable::query()
					->setSelect(['ENTITY_ID', 'SOURCE_ID' => 'TRACE.SOURCE_ID'])
					->whereIn('ID', array_map('intval', array_column($lastBindingIds, 'LAST_BINDING_ID')))
					->fetchAll()
			;
			foreach ($rows as $row)
			{
				$sourceId = (int)$row['SOURCE_ID'];
				$sourceIds[(int)$row['ENTITY_ID']] = $sourceId > Trace::NO_SOURCE ? $sourceId : null;
			}
		}

		return $sourceIds;
	}

	/**
	 * Whether the trace of the current binding of the item is also its newest trace by date, strictly: the
	 * reading of legacy takes the newest trace as the current one, so only then both readings agree. An
	 * item without traces agrees with itself.
	 */
	public function isLastBoundTraceNewest(int $entityTypeId, int $entityId): bool
	{
		$binding = $this->getLastBinding($entityTypeId, $entityId);
		if ($binding === null)
		{
			return true;
		}

		$traceId = $binding['traceId'];
		$trace = TraceTable::query()->setSelect(['DATE_CREATE'])->where('ID', $traceId)->fetch();
		$date = $trace['DATE_CREATE'] ?? null;

		$query =
			TraceEntityTable::query()
				->setSelect(['ID'])
				->where('ENTITY_TYPE_ID', $entityTypeId)
				->where('ENTITY_ID', $entityId)
				->whereNot('TRACE_ID', $traceId)
				->setLimit(1)
		;
		if ($date instanceof DateTime)
		{
			// A trace of the same date is not older: the order of legacy between the two is undefined.
			$query->where('TRACE.DATE_CREATE', '>=', $date);
		}

		return $query->fetch() === false;
	}

	public function countBindings(int $traceId): int
	{
		return TraceEntityTable::getCount(['=TRACE_ID' => $traceId]);
	}

	/**
	 * @param int[] $traceIds
	 * @return array<int, Trace> trace id => trace
	 */
	private function loadTraces(array $traceIds): array
	{
		if (empty($traceIds))
		{
			return [];
		}

		$rows =
			TraceTable::query()
				->setSelect(['ID', 'SOURCE_ID', 'DATE_CREATE', 'IS_MOBILE', 'TAGS_RAW', 'PAGES_RAW'])
				->whereIn('ID', $traceIds)
				->fetchAll()
		;
		$channels = $this->loadChannels($traceIds);

		$traces = [];
		foreach ($rows as $row)
		{
			$traceId = (int)$row['ID'];
			$traces[$traceId] = new Trace(
				id: $traceId,
				sourceId: max(Trace::NO_SOURCE, (int)$row['SOURCE_ID']),
				createdTime: $row['DATE_CREATE'] instanceof DateTime ? $row['DATE_CREATE'] : new DateTime(),
				isMobile: $row['IS_MOBILE'] === true || $row['IS_MOBILE'] === 'Y',
				pages: self::mapPages($row['PAGES_RAW']),
				channels: $channels[$traceId] ?? [],
				utm: self::mapUtm($row['TAGS_RAW']),
			);
		}

		return $traces;
	}

	/**
	 * Channels legacy has and V2 does not know yet are left out: the comparison test of ChannelCode
	 * catches such a channel.
	 *
	 * @param int[] $traceIds
	 * @return array<int, TraceChannel[]> trace id => channels
	 */
	private function loadChannels(array $traceIds): array
	{
		$rows =
			TraceChannelTable::query()
				->setSelect(['TRACE_ID', 'CODE', 'VALUE'])
				->whereIn('TRACE_ID', $traceIds)
				->setOrder(['ID' => 'ASC'])
				->fetchAll()
		;

		$channels = [];
		foreach ($rows as $row)
		{
			$code = ChannelCode::tryFrom((string)$row['CODE']);
			if ($code !== null)
			{
				$channels[(int)$row['TRACE_ID']][] = new TraceChannel($code, (string)$row['VALUE']);
			}
		}

		return $channels;
	}

	/**
	 * @return TracePage[]
	 */
	private static function mapPages(mixed $rawPages): array
	{
		if (!is_array($rawPages))
		{
			return [];
		}

		$pages = [];
		foreach ($rawPages as $rawPage)
		{
			if (!is_array($rawPage) || !isset($rawPage['URL']))
			{
				continue;
			}

			$timestamp = (int)($rawPage['DATE_INSERT'] ?? 0);
			$pages[] = new TracePage(
				(string)$rawPage['URL'],
				isset($rawPage['TITLE']) ? (string)$rawPage['TITLE'] : null,
				$timestamp > 0 ? DateTime::createFromTimestamp($timestamp) : null,
			);
		}

		return $pages;
	}

	private static function mapUtm(mixed $rawTags): ?Utm
	{
		if (!is_array($rawTags))
		{
			return null;
		}

		$utm = null;
		foreach ($rawTags as $code => $value)
		{
			if (!is_scalar($value) || (string)$value === '')
			{
				continue;
			}

			$value = (string)$value;
			match ($code)
			{
				UtmTable::ENUM_CODE_UTM_SOURCE => ($utm ??= new Utm())->setSource($value),
				UtmTable::ENUM_CODE_UTM_MEDIUM => ($utm ??= new Utm())->setMedium($value),
				UtmTable::ENUM_CODE_UTM_CAMPAIGN => ($utm ??= new Utm())->setCampaign($value),
				UtmTable::ENUM_CODE_UTM_CONTENT => ($utm ??= new Utm())->setContent($value),
				UtmTable::ENUM_CODE_UTM_TERM => ($utm ??= new Utm())->setTerm($value),
				default => null,
			};
		}

		return $utm;
	}
}
