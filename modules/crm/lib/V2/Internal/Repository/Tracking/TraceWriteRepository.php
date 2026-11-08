<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Tracking;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Tracking\Internals\TraceChannelTable;
use Bitrix\Crm\Tracking\Internals\TraceEntityTable;
use Bitrix\Crm\Tracking\Internals\TraceSourceTable;
use Bitrix\Crm\Tracking\Internals\TraceTable;
use Bitrix\Crm\UtmTable;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Trace;
use Bitrix\Crm\V2\Internal\Entity\Tracking\TracePage;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceBindingsChangedException;
use Bitrix\Crm\V2\Public\Entity\Item\Utm;
use Bitrix\Main\EventManager;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Result;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * Writes traces and their bindings to the tables legacy reads them from, in the format legacy stores
 * them: UTM tags and pages are serialized into the trace row, channels and bindings are rows of their own.
 *
 * Only the table layer is used: the operations of legacy detect and reuse traces, link the previous
 * visits and send the analytics event, and none of that belongs to an explicit write.
 *
 * Every refusal of the storage is thrown, so the caller can roll its transaction back.
 *
 * @internal
 */
final class TraceWriteRepository
{
	/**
	 * Stores a new trace with its channels; the id of the given trace is ignored.
	 *
	 * Legacy keeps one channel of a code per trace, so the first channel of a code wins.
	 *
	 * @throws SystemException
	 */
	public function create(Trace $trace): int
	{
		$traceId = $this->addTraceRow([
			'SOURCE_ID' => $trace->sourceId,
			'DATE_CREATE' => clone $trace->createdTime,
			'IS_MOBILE' => $trace->isMobile ? 'Y' : 'N',
			'HAS_CHILD' => 'N',
			'TAGS_RAW' => self::toTags($trace->utm),
			'PAGES_RAW' => array_map(self::mapPage(...), $trace->pages),
		]);

		$codes = [];
		foreach ($trace->channels as $channel)
		{
			if (isset($codes[$channel->code->value]))
			{
				continue;
			}

			$codes[$channel->code->value] = true;
			$this->addChannelRow($traceId, $channel->code->value, $channel->value);
		}

		return $traceId;
	}

	/**
	 * Binds the trace to the item, unless it is bound already.
	 *
	 * @throws SystemException
	 */
	public function bind(int $traceId, ItemIdentifier $item): void
	{
		$existing =
			TraceEntityTable::query()
				->setSelect(['ID'])
				->where('TRACE_ID', $traceId)
				->where('ENTITY_TYPE_ID', $item->getEntityTypeId())
				->where('ENTITY_ID', $item->getEntityId())
				->setLimit(1)
				->fetch()
		;
		if ($existing)
		{
			return;
		}

		self::assertSuccess(
			TraceEntityTable::add([
				'TRACE_ID' => $traceId,
				'ENTITY_TYPE_ID' => $item->getEntityTypeId(),
				'ENTITY_ID' => $item->getEntityId(),
			]),
			'bind the trace',
		);
	}

	/**
	 * Binds a trace just created to the items: a new trace has no bindings yet, so none is looked for.
	 *
	 * @param ItemIdentifier[] $items distinct items
	 * @throws SystemException
	 */
	public function bindNew(int $traceId, array $items): void
	{
		foreach ($items as $item)
		{
			self::assertSuccess(
				TraceEntityTable::add([
					'TRACE_ID' => $traceId,
					'ENTITY_TYPE_ID' => $item->getEntityTypeId(),
					'ENTITY_ID' => $item->getEntityId(),
				]),
				'bind the trace',
			);
		}
	}

	/**
	 * Stores a copy of the trace with another source and date: pages, UTM tags, the mobile flag and the
	 * channels are copied as they are stored. The links to the previous visits and the levels of the
	 * source are not copied: they belong to the visit the original trace was detected from.
	 *
	 * @param int $sourceId 0 means no source
	 * @throws SystemException the trace does not exist
	 */
	public function copyWithSource(int $traceId, int $sourceId, DateTime $now): int
	{
		$row =
			TraceTable::query()
				->setSelect(['IS_MOBILE', 'TAGS_RAW', 'PAGES_RAW'])
				->where('ID', $traceId)
				->fetch()
		;
		if (!$row)
		{
			throw new SystemException(sprintf('Tracking trace `%d` to copy was not found', $traceId));
		}

		$copyId = $this->addTraceRow([
			'SOURCE_ID' => max(Trace::NO_SOURCE, $sourceId),
			'DATE_CREATE' => clone $now,
			'IS_MOBILE' => $row['IS_MOBILE'] === 'Y' || $row['IS_MOBILE'] === true ? 'Y' : 'N',
			'HAS_CHILD' => 'N',
			'TAGS_RAW' => is_array($row['TAGS_RAW']) ? $row['TAGS_RAW'] : [],
			'PAGES_RAW' => is_array($row['PAGES_RAW']) ? $row['PAGES_RAW'] : [],
		]);

		$channels =
			TraceChannelTable::query()
				->setSelect(['CODE', 'VALUE'])
				->where('TRACE_ID', $traceId)
				->setOrder(['ID' => 'ASC'])
				->fetchAll()
		;
		foreach ($channels as $channel)
		{
			$this->addChannelRow($copyId, (string)$channel['CODE'], (string)$channel['VALUE']);
		}

		return $copyId;
	}

	/**
	 * Moves the binding to another trace, provided it still points at the trace the caller read.
	 *
	 * The comparison and the write are one statement, so a binding moved by a parallel request since it
	 * was read is not moved again.
	 *
	 * @return bool false says the binding is gone or points at another trace by now
	 */
	public function rebindIfUnchanged(int $bindingId, int $oldTraceId, int $newTraceId): bool
	{
		$entity = TraceEntityTable::getEntity();
		$connection = $entity->getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$connection->queryExecute(sprintf(
			'UPDATE %s SET %s = %d WHERE %s = %d AND %s = %d',
			$sqlHelper->quote($entity->getDBTableName()),
			$sqlHelper->quote('TRACE_ID'),
			$newTraceId,
			$sqlHelper->quote('ID'),
			$bindingId,
			$sqlHelper->quote('TRACE_ID'),
			$oldTraceId,
		));

		return $connection->getAffectedRowsCount() === 1;
	}

	/**
	 * Deletes the trace like {@see delete()}, taking away the given bindings of it only. A binding the
	 * caller has not read - added by a parallel request after the read - stops the delete, so the caller
	 * rolls its transaction back instead of taking the binding away unchecked; a binding committed after
	 * the bindings of the trace are taken away is left as it is.
	 *
	 * @param int[] $bindingIds the bindings of the trace the caller has read and checked
	 * @throws TraceBindingsChangedException the trace has a binding besides the given ones
	 * @throws SystemException
	 */
	public function deleteWithBindings(int $traceId, array $bindingIds): void
	{
		$allowed = array_fill_keys(array_map('intval', $bindingIds), true);
		$entity = TraceEntityTable::getEntity();
		$moduleId = $entity->getModule();
		$eventType = $entity->getNamespace() . $entity->getName() . '::' . DataManager::EVENT_ON_BEFORE_DELETE;
		// The cascade of the table layer deletes every binding it finds; an unknown one stops it here.
		$handlerKey = EventManager::getInstance()->addEventHandler(
			$moduleId,
			$eventType,
			static function (Event $event) use ($allowed): void
			{
				$primary = $event->getParameter('primary');
				$bindingId = (int)(is_array($primary) ? ($primary['ID'] ?? 0) : $primary);
				if (!isset($allowed[$bindingId]))
				{
					throw new TraceBindingsChangedException();
				}
			},
		);

		try
		{
			$this->delete($traceId);
		}
		finally
		{
			EventManager::getInstance()->removeEventHandler($moduleId, $eventType, $handlerKey);
		}
	}

	/**
	 * Deletes the trace with everything that refers to it.
	 *
	 * The table layer takes the bindings, the channels and the links to the previous visits away on its
	 * own ({@see TraceTable::onDelete()}); the levels of its source are left by it, so they are removed here
	 * (by the unique index of TRACE_ID and LEVEL). The link of a later visit to this one is left, the way
	 * the delete of legacy leaves it: the tree has no index by CHILD_ID to find it without a full scan.
	 *
	 * @throws SystemException
	 */
	public function delete(int $traceId): void
	{
		self::assertSuccess(TraceTable::delete($traceId), 'delete the trace');

		$levels =
			TraceSourceTable::query()
				->setSelect(['ID'])
				->where('TRACE_ID', $traceId)
				->fetchAll()
		;
		foreach ($levels as $level)
		{
			self::assertSuccess(TraceSourceTable::delete($level['ID']), 'delete the source level of the trace');
		}
	}

	/**
	 * The UTM tags in the format the trace stores them: UTM code => value, empty values left out.
	 *
	 * @return array<string, string>
	 */
	public static function toTags(?Utm $utm): array
	{
		if ($utm === null)
		{
			return [];
		}

		$tags = [
			UtmTable::ENUM_CODE_UTM_SOURCE => $utm->getSource(),
			UtmTable::ENUM_CODE_UTM_MEDIUM => $utm->getMedium(),
			UtmTable::ENUM_CODE_UTM_CAMPAIGN => $utm->getCampaign(),
			UtmTable::ENUM_CODE_UTM_CONTENT => $utm->getContent(),
			UtmTable::ENUM_CODE_UTM_TERM => $utm->getTerm(),
		];

		return array_filter($tags, static fn(?string $value): bool => $value !== null && $value !== '');
	}

	/**
	 * @throws SystemException
	 */
	private function addTraceRow(array $fields): int
	{
		$result = TraceTable::add($fields);
		self::assertSuccess($result, 'add the trace');

		return (int)$result->getId();
	}

	/**
	 * @throws SystemException
	 */
	private function addChannelRow(int $traceId, string $code, string $value): void
	{
		self::assertSuccess(
			TraceChannelTable::add([
				'TRACE_ID' => $traceId,
				'CODE' => $code,
				'VALUE' => $value,
			]),
			'add the channel of the trace',
		);
	}

	/**
	 * @throws SystemException
	 */
	private static function assertSuccess(Result $result, string $action): void
	{
		if (!$result->isSuccess())
		{
			throw new SystemException(
				sprintf('Could not %s: %s', $action, implode('; ', $result->getErrorMessages())),
			);
		}
	}

	/**
	 * The keys are always present: the trace screen of legacy reads them without a check.
	 *
	 * @return array{URL: string, DATE_INSERT: int|null, TITLE: string|null}
	 */
	private static function mapPage(TracePage $page): array
	{
		return [
			'URL' => $page->url,
			'DATE_INSERT' => $page->time?->getTimestamp(),
			'TITLE' => $page->title,
		];
	}
}
