<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Journal;

use Bitrix\Disk\Access\AccessCodeEnum;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\AccessScopeDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ChangeDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ObjectCardDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SectionsStructure;
use Bitrix\Disk\Internal\Model\SyncLogCodeTable;
use Bitrix\Disk\Internal\Model\SyncLogTable;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Card\CardCollector;
use Bitrix\Disk\Internal\Service\Sync\Position\JournalCursor;
use Bitrix\Disk\Internal\Service\Sync\Snapshot\AccessSourceConfirmerInterface;
use Bitrix\Disk\Internal\Service\Sync\StorageListProvider;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Disk\Internal\Service\Sync\Visibility\AccessSourceConfirmer;
use Bitrix\Disk\Internals\TrackedObjectTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use CAccess;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One personal page of the journal of changes (ALG-06).
 *
 * A page is the intersection of the codes of the reader with the codes the records were published to,
 * taken in the order of the identifiers, so the reading is bounded by the codes of the user rather
 * than by how much of the portal they happen to see. What comes back is filtered again and only then
 * described, and each of the four steps below closes a failure of its own.
 *
 * The position moves whatever the page turns out to hold. A read that survived nothing - every record
 * of it belonged to an object the user has since lost sight of - is an ordinary state of the journal
 * and not its end, and a position that stayed behind would send the client back to the same records
 * for as long as it kept polling.
 *
 * A tombstone and a change of access are not checked against visibility a second time. Both are about
 * an object the reader can no longer see, which is the whole of what they say; asking the rights again
 * would drop exactly the record the client is waiting for and leave it holding a file that is gone.
 *
 * A short page is not the end of the data. Records fall out to the filtering, so a page is filled up
 * from the ones behind it - at most {@see MAX_PAGE_READS} reads - and whether the journal goes on is
 * answered by the position against the watermark of {@see SafeWindow}, never by how many records the
 * page ended up with.
 *
 * A record that cannot be described does not stop the page. An object that is visible but has no card
 * leaves a warning naming its kind and its identifier, which is enough for the client to read it back
 * on its own; a record whose stored shape cannot be built at all leaves the same warning under a
 * reason of its own and is written to the log of the portal besides. Either way the page is formed and
 * the position moves past it.
 */
final class JournalReader
{
	/** Why a record of the page arrives without a card: the object is gone or is of no readable kind. */
	public const WARNING_REASON_OBJECT_UNAVAILABLE = 'OBJECT_UNAVAILABLE';

	/**
	 * Why a record of the page did not arrive at all: what it holds is outside the contract - a kind,
	 * a type of change or a reason for a tombstone this node does not know. A portal deployed node by
	 * node reads such records while the rollout lasts.
	 */
	public const WARNING_REASON_RECORD_UNREADABLE = 'RECORD_UNREADABLE';

	/**
	 * How many reads of the journal one page may cost. Without it a stretch of records whose objects
	 * the user has all lost sight of would be walked to the end of the journal by a single request.
	 */
	private const MAX_PAGE_READS = 5;

	/**
	 * The sections whose assembly is expensive enough to halve the page, as
	 * {@see SyncLimits::getPageSizeMaxHeavy()} is published for.
	 */
	private const HEAVY_SECTIONS = [SectionsStructure::SECTION_LOCATION];

	/** How many identifiers, or codes, one statement carries in a list. */
	private const IDS_PER_QUERY = 300;

	private const LOGGER_CHANNEL = 'sync';

	private SafeWindow $safeWindow;
	private CardCollector $cards;
	private StorageListProvider $storages;
	private AccessSourceConfirmerInterface $accessSourceConfirmer;
	private SyncLimits $limits;
	private LoggerInterface $logger;

	public function __construct(
		?SafeWindow $safeWindow = null,
		?CardCollector $cards = null,
		?StorageListProvider $storages = null,
		?AccessSourceConfirmerInterface $accessSourceConfirmer = null,
		?SyncLimits $limits = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->safeWindow = $safeWindow ?? new SafeWindow();
		$this->cards = $cards ?? new CardCollector();
		$this->storages = $storages ?? new StorageListProvider();
		$this->accessSourceConfirmer = $accessSourceConfirmer ?? new AccessSourceConfirmer();
		$this->limits = $limits ?? new SyncLimits();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'journal reader');
	}

	/**
	 * The changes addressed to the owner of the cursor, from where it stands.
	 *
	 * @param int $limit what the caller asked for; what is served is capped by the ceiling of the
	 *        requested sections
	 * @return array{
	 *     items: ChangeDto[],
	 *     warnings: array<int, array{resourceType: string, resourceId: int, reason: string}>,
	 *     hasMore: bool,
	 *     cursor: JournalCursor
	 * }
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function page(JournalCursor $cursor, int $limit, SectionsStructure $sections): array
	{
		$userId = $cursor->getUserId();
		$limit = $this->resolveLimit($limit, $sections);
		$codes = self::accessCodesOf($userId);
		$upper = $this->safeWindow->maxPublishedId();

		$position = $cursor->getLastRecordId();
		$produced = [];
		$reads = 0;
		// The storages of the reader, worked out at most once for the whole page: the question is a
		// pass over the storages of the entire portal, and a page costs up to MAX_PAGE_READS reads.
		$visibleStorageIds = null;

		while (count($produced) < $limit && $reads < self::MAX_PAGE_READS)
		{
			$reads++;

			$rows = $this->readRows($codes, $position, $upper, $limit + 1);
			if ($rows === [])
			{
				// Nothing addressed to this user lies between the position and the watermark, so the
				// position belongs at the watermark itself: left behind, it would answer "there is
				// more" on every poll and send the client back over the same empty stretch.
				$position = max($position, $upper);

				break;
			}

			$position = (int)$rows[array_key_last($rows)]['ID'];

			foreach ($this->render($rows, $userId, $sections, $visibleStorageIds) as $entry)
			{
				$produced[] = $entry;
			}
		}

		// What was produced beyond the page is left for the next one rather than thrown away, so the
		// position stops at the last record actually handed over.
		if (count($produced) > $limit)
		{
			$produced = array_slice($produced, 0, $limit);
			$position = $produced[array_key_last($produced)]['logId'];
		}

		return [
			'items' => array_values(array_filter(array_column($produced, 'item'))),
			'warnings' => array_values(array_filter(array_column($produced, 'warning'))),
			'hasMore' => $position < $upper,
			'cursor' => $cursor->withPosition($position),
		];
	}

	/**
	 * One read worth of records, turned into what the page carries.
	 *
	 * Everything the batch needs is asked for the batch as a whole - the sources of access behind its
	 * objects, then the cards of the ones that survived - because a question per record would be a
	 * query per record over tables of millions of rows.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param int[]|null $visibleStorageIds the storages of the reader, carried across the reads of one
	 *        page: null until a read of that page actually needs them
	 * @return array<int, array{
	 *     logId: int,
	 *     item: ChangeDto|null,
	 *     warning: array{resourceType: string, resourceId: int, reason: string}|null
	 * }>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function render(
		array $rows,
		int $userId,
		SectionsStructure $sections,
		?array &$visibleStorageIds,
	): array
	{
		$realObjectIds = [];
		foreach ($rows as $row)
		{
			if (self::isObjectChange($row))
			{
				$realObjectIds[(int)$row['RESOURCE_ID']] = (int)$row['RESOURCE_ID'];
			}
		}

		$visible = $this->confirmVisibility(array_values($realObjectIds), $userId);
		$cards = $this->collectCards(array_keys($visible), $userId, $sections, $visibleStorageIds);

		$entries = [];
		foreach ($rows as $row)
		{
			$card = null;

			if (self::isObjectChange($row))
			{
				$realObjectId = (int)$row['RESOURCE_ID'];
				if (!isset($visible[$realObjectId]))
				{
					// The codes the record was published to have aged: the reader still holds one of
					// them, the object behind it is no longer theirs to see.
					continue;
				}

				$card = $cards[$realObjectId] ?? null;
				if ($card === null)
				{
					$entries[] = [
						'logId' => (int)$row['ID'],
						'item' => null,
						'warning' => self::warningOf($row, self::WARNING_REASON_OBJECT_UNAVAILABLE),
					];

					continue;
				}
			}

			$item = $this->build($row, $card);
			if ($item !== null)
			{
				$entries[] = ['logId' => (int)$row['ID'], 'item' => $item, 'warning' => null];

				continue;
			}

			$warning = self::warningOfAnUnreadableRecord($row);
			if ($warning !== null)
			{
				$entries[] = ['logId' => (int)$row['ID'], 'item' => null, 'warning' => $warning];
			}
		}

		return $entries;
	}

	/**
	 * The records of one read: the identifiers first, over the index the codes and the order live in
	 * together, and the records themselves by those identifiers.
	 *
	 * @param string[] $codes
	 * @return array<int, array<string, mixed>>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readRows(array $codes, int $after, int $upTo, int $take): array
	{
		if ($codes === [] || $after >= $upTo || $take <= 0)
		{
			return [];
		}

		$found = $this->firstRecordIdsAddressedTo($codes, $after, $upTo, $take);
		if ($found === [])
		{
			return [];
		}

		$rows = SyncLogTable::query()
			->setSelect([
				'ID',
				'CHANGE_TYPE',
				'OPERATION',
				'RESOURCE_TYPE',
				'RESOURCE_ID',
				'AUTHOR_ID',
				'CREATED_AT',
				'CHANGED_FIELDS',
				'TOMBSTONE_REASON',
				'ACCESS_SCOPE',
				'SCOPE_OBJECT_ID',
			])
			->whereIn('ID', $found)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		return $rows;
	}

	/**
	 * The identifiers the read starts from: the lowest ones above the position that any code of the
	 * reader addresses.
	 *
	 * The codes go in portions rather than as one list. A code of a workgroup, of a department or of
	 * a chat is a code like any other - sharing into a chat writes them into the journal by design -
	 * so the list of a real reader is thousands of entries long and cannot be narrowed by the family
	 * of a code without losing records. What can be done is to ask in portions: each of them takes the
	 * first {@see IDS_PER_QUERY} codes at most, and since every portion is asked for the same window
	 * and ordered the same way, the lowest identifiers of the portions hold the lowest identifiers of
	 * the whole.
	 *
	 * @param string[] $codes
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function firstRecordIdsAddressedTo(array $codes, int $after, int $upTo, int $take): array
	{
		$found = [];
		foreach (array_chunk($codes, self::IDS_PER_QUERY) as $chunk)
		{
			$rows = SyncLogCodeTable::query()
				->setSelect(['LOG_ID'])
				->whereIn('ACCESS_CODE', $chunk)
				->where('LOG_ID', '>', $after)
				->where('LOG_ID', '<=', $upTo)
				->setDistinct()
				->setOrder(['LOG_ID' => 'ASC'])
				->setLimit($take)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$found[(int)$row['LOG_ID']] = true;
			}
		}

		$found = array_keys($found);
		sort($found);

		return array_slice($found, 0, $take);
	}

	/**
	 * ALG-05 over the objects of a batch: presence of a code on a record is not visibility, and the
	 * source the visibility of the reader stood on may have been taken away since the record was
	 * written. The set of interaction is asked about as well, because a record addressed by a personal
	 * code belongs to a reader the rights of the module never reached.
	 *
	 * The two questions are not one. An object of the page is asked about by the rights of Disk alone,
	 * and the sources that are not personal - a link, an attachment - are asked only for the rows the
	 * reader actually holds in the set. A record travels by the codes fixed on it when it was
	 * published, and those codes age: asking a link about an object of such a record would confirm it
	 * to a reader who never touched the object and whose rights on it are gone.
	 *
	 * @param int[] $realObjectIds
	 * @return array<int, true>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function confirmVisibility(array $realObjectIds, int $userId): array
	{
		if ($realObjectIds === [])
		{
			return [];
		}

		$confirmed = $this->accessSourceConfirmer->visibleByDiskRights($realObjectIds, $userId);

		$now = new DateTime();
		$rows = [];
		foreach ($this->interactionRowsOf(self::without($realObjectIds, $confirmed), $userId) as $row)
		{
			$rows[] = $row + ['freshnessAt' => $now];
		}

		foreach ($this->accessSourceConfirmer->confirm($rows, $userId) as $row)
		{
			$confirmed[$row['realObjectId']] = true;
		}

		return $confirmed;
	}

	/**
	 * @param int[] $realObjectIds
	 * @param array<int, true> $confirmed
	 * @return int[]
	 */
	private static function without(array $realObjectIds, array $confirmed): array
	{
		return array_values(array_filter(
			$realObjectIds,
			static fn (int $realObjectId): bool => !isset($confirmed[$realObjectId]),
		));
	}

	/**
	 * The rows of the interaction set of the user over the objects of the batch. A link carries rights
	 * and links of its own, so it travels beside the real object rather than instead of it.
	 *
	 * @param int[] $realObjectIds
	 * @return array<int, array{objectId: int, realObjectId: int, attachedObjectId: int|null}>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function interactionRowsOf(array $realObjectIds, int $userId): array
	{
		$rows = [];
		foreach (array_chunk($realObjectIds, self::IDS_PER_QUERY) as $chunk)
		{
			$cursor = TrackedObjectTable::getList([
				'select' => ['OBJECT_ID', 'REAL_OBJECT_ID', 'ATTACHED_OBJECT_ID'],
				'filter' => Query::filter()->where('USER_ID', $userId)->whereIn('REAL_OBJECT_ID', $chunk),
			]);

			while ($row = $cursor->fetch())
			{
				$rows[] = [
					'objectId' => (int)$row['OBJECT_ID'],
					'realObjectId' => (int)$row['REAL_OBJECT_ID'],
					'attachedObjectId' => $row['ATTACHED_OBJECT_ID'] === null
						? null
						: (int)$row['ATTACHED_OBJECT_ID']
					,
				];
			}
		}

		return $rows;
	}

	/**
	 * The cards of the objects of one read.
	 *
	 * Where a card names is looked for inside the storages the reader sees now, and the list is read
	 * from the rights rather than from the session on purpose: the journal delivers a root that has
	 * only just become visible, and the places of such a record belong to the set the reader holds at
	 * this moment. What it must not do is ask more than once - the answer is the same for the whole
	 * page and costs a pass over the storages of the portal - or ask at all where no card of the page
	 * is going to name a place.
	 *
	 * @param int[] $realObjectIds
	 * @param int[]|null $visibleStorageIds see {@see render()}
	 * @return array<int, ObjectCardDto> keyed by the object
	 */
	private function collectCards(
		array $realObjectIds,
		int $userId,
		SectionsStructure $sections,
		?array &$visibleStorageIds,
	): array
	{
		if ($realObjectIds === [])
		{
			return [];
		}

		try
		{
			if ($sections->has(SectionsStructure::SECTION_LOCATION))
			{
				$visibleStorageIds ??= array_keys($this->storages->getVisibleStorages($userId));
			}

			$collected = $this->cards->collect($userId, $realObjectIds, $visibleStorageIds ?? [], $sections);
		}
		catch (Throwable $exception)
		{
			// A batch that cannot be assembled at all leaves its records as warnings instead of ending
			// the request: the page is still formed and the position still moves, so one unreadable
			// object cannot stop the journal of a client for good.
			$this->logger->error(
				'the journal did not assemble the cards of a page: {message}',
				['message' => $exception->getMessage(), 'exception' => $exception],
			);

			return [];
		}

		$byObject = [];
		foreach ($collected as $card)
		{
			$byObject[$card->objectId] = $card;
		}

		return $byObject;
	}

	/**
	 * The record as the client reads it, or null when its stored shape cannot be built into one.
	 *
	 * A change of access whose resource is missing while its scope names an object is the shape that
	 * actually occurs: the writer works the kind of the resource out by reading the object, and an
	 * object already deleted by then leaves the column empty. Nothing can be published for it - the
	 * scope of the answer names an object and is worthless without its kind - so the record is passed
	 * over, and the position moves on regardless.
	 *
	 * @param array<string, mixed> $row
	 */
	private function build(array $row, ?ObjectCardDto $card): ?ChangeDto
	{
		$changeType = ChangeType::tryFrom((string)$row['CHANGE_TYPE']);
		if ($changeType === null)
		{
			$this->logUnreadable($row, 'change type is not one of the contract');

			return null;
		}

		try
		{
			if ($changeType === ChangeType::AccessChanged)
			{
				return self::buildAccessChange($row);
			}

			if (self::isTombstone($row))
			{
				return self::buildTombstone($row);
			}

			return self::buildObjectChange($row, $changeType, $card);
		}
		catch (ArgumentOutOfRangeException $exception)
		{
			$this->logUnreadable($row, $exception->getMessage());

			return null;
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @throws ArgumentOutOfRangeException
	 */
	private static function buildObjectChange(array $row, ChangeType $changeType, ?ObjectCardDto $card): ChangeDto
	{
		return ChangeDto::objectChange(
			(string)$row['ID'],
			$changeType,
			$row['CREATED_AT'],
			(string)$row['RESOURCE_TYPE'],
			(int)$row['RESOURCE_ID'],
			self::optionalInt($row['AUTHOR_ID']),
			self::decodeChangedFields($row['CHANGED_FIELDS']),
			$card,
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @throws ArgumentOutOfRangeException
	 */
	private static function buildTombstone(array $row): ChangeDto
	{
		$reason = TombstoneReason::tryFrom((string)$row['TOMBSTONE_REASON']);
		if ($reason === null)
		{
			throw new ArgumentOutOfRangeException('tombstoneReason');
		}

		return ChangeDto::tombstone(
			(string)$row['ID'],
			$row['CREATED_AT'],
			(string)$row['RESOURCE_TYPE'],
			(int)$row['RESOURCE_ID'],
			$reason,
			self::optionalInt($row['AUTHOR_ID']),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @throws ArgumentOutOfRangeException
	 */
	private static function buildAccessChange(array $row): ChangeDto
	{
		return ChangeDto::accessChange(
			(string)$row['ID'],
			$row['CREATED_AT'],
			AccessScopeDto::ofJournalScope((string)$row['ACCESS_SCOPE'], self::optionalInt($row['SCOPE_OBJECT_ID'])),
			$row['RESOURCE_TYPE'] === null ? null : (string)$row['RESOURCE_TYPE'],
		);
	}

	/**
	 * A change of an object is what a card is assembled for. The other two shapes carry none and are
	 * handed over by the codes fixed on the record.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function isObjectChange(array $row): bool
	{
		return !self::isTombstone($row) && (string)$row['CHANGE_TYPE'] !== ChangeType::AccessChanged->value;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function isTombstone(array $row): bool
	{
		return (string)$row['OPERATION'] === ChangeRecord::OPERATION_TOMBSTONE
			|| (string)$row['CHANGE_TYPE'] === ChangeType::Deleted->value
		;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{resourceType: string, resourceId: int, reason: string}
	 */
	private static function warningOf(array $row, string $reason): array
	{
		return [
			'resourceType' => (string)$row['RESOURCE_TYPE'],
			'resourceId' => (int)$row['RESOURCE_ID'],
			'reason' => $reason,
		];
	}

	/**
	 * A record whose stored shape cannot be built leaves the page as a warning, or leaves no trace of
	 * itself at all when it names no resource to warn about.
	 *
	 * Naming the resource is the whole worth of the warning: without it the client has nothing to
	 * hand to the check of state, and a tombstone lost that way would leave a deleted file in its
	 * memory for good - the position moves past the record and the journal never offers it again. A
	 * change of access that lost the kind of its scope names no resource, and one of those is what the
	 * journals written before the writer was stopped from producing them still hold.
	 *
	 * @param array<string, mixed> $row
	 * @return array{resourceType: string, resourceId: int, reason: string}|null
	 */
	private static function warningOfAnUnreadableRecord(array $row): ?array
	{
		if ((string)$row['RESOURCE_TYPE'] === '' || (int)$row['RESOURCE_ID'] <= 0)
		{
			return null;
		}

		return self::warningOf($row, self::WARNING_REASON_RECORD_UNREADABLE);
	}

	/**
	 * The codes the page is filtered by.
	 *
	 * Two of them are not rows of b_user_access and no provider of the portal produces them, so they
	 * are added here or they are added nowhere. AU stands for any authorised user and is written as it
	 * is on the records of everything the portal opened to everyone; the personal code is the whole of
	 * the addressing behind a set of interaction, a tombstone meant for one reader and the signal that
	 * the codes of a reader themselves have moved.
	 *
	 * @return string[]
	 */
	private static function accessCodesOf(int $userId): array
	{
		$codes = CAccess::GetUserCodesArray($userId);
		$codes[] = AccessCodeEnum::AUTHORIZED_USER->value;
		$codes[] = AudienceResolver::personalCodeOf($userId);

		$codes = array_filter(
			array_map('strval', $codes),
			static fn (string $code): bool => $code !== '',
		);

		return array_values(array_unique($codes));
	}

	/**
	 * The names of the fields as the publication fixed them. A list that cannot be read back is the
	 * same as no list at all: the client re-reads the whole card and loses nothing.
	 *
	 * @return string[]|null
	 */
	private static function decodeChangedFields(mixed $stored): ?array
	{
		if (!is_string($stored) || $stored === '')
		{
			return null;
		}

		try
		{
			$decoded = Json::decode($stored);
		}
		catch (ArgumentException)
		{
			return null;
		}

		return is_array($decoded) ? array_values(array_map('strval', $decoded)) : null;
	}

	private static function optionalInt(mixed $value): ?int
	{
		return $value === null ? null : (int)$value;
	}

	/**
	 * The ceiling of a page follows the cost of the sections that were asked for: the light one is what
	 * the portal publishes as its maximum, the heavy one half of it.
	 */
	private function resolveLimit(int $requested, SectionsStructure $sections): int
	{
		$ceiling = $this->hasHeavySection($sections)
			? $this->limits->getPageSizeMaxHeavy()
			: $this->limits->getPageSizeMax()
		;

		return max(1, min($requested, $ceiling));
	}

	private function hasHeavySection(SectionsStructure $sections): bool
	{
		foreach (self::HEAVY_SECTIONS as $section)
		{
			if ($sections->has($section))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function logUnreadable(array $row, string $reason): void
	{
		$this->logger->warning(
			'a record of the journal was passed over as unreadable: {id}, {reason}',
			['id' => (string)$row['ID'], 'reason' => $reason],
		);
	}
}
