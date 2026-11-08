<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Visibility;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Model\UnifiedLinkAccessTable;
use Bitrix\Disk\Internal\Service\Sync\Snapshot\AccessSourceConfirmerInterface;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * The source of access behind a row of the interaction set, confirmed for a whole page at once
 * (ALG-05).
 *
 * A row of the set is written when the user touches an object, and it stays there afterwards. The
 * access it was written for may since have been taken away - the rights of Disk revoked, the link it
 * was opened through switched off - so presence in the set is not visibility. Without this
 * confirmation the event of such a revocation would be idle: the client would sync, be told it may
 * see the object, and keep something it can no longer open.
 *
 * Both checks are batch and both stay inside the module. Two queries answer for the whole page,
 * whatever its size - one, when the sources beyond the rights are not asked at all - and no connector
 * of another module is asked anything: per-object questions are exactly what a page of a hundred
 * objects cannot afford.
 *
 * A row is confirmed by any one of three sources, and they are asked in this order:
 *
 *  1. the object is visible by the rights of Disk;
 *  2. a link that grants reading is alive on it - an external link that exists and has not expired,
 *     or a unified link whose stored level is not the denied one;
 *  3. the row carries an attachment of the object into an entity of another module.
 *
 * The last two sources are not personal - a link answers for whoever holds it, and an attachment is
 * counted by the identifier the row carries - so for a reader of the extranet or of a collab they are
 * not asked at all ({@see ReaderCategory}). The page of such a reader costs the one query of the
 * rights, and its rows fall the moment the rights of Disk do.
 *
 * The third branch is a declared narrowing rather than a stub: the connector of the owning module
 * answers one object at a time, and connecting them belongs to stage 3. Until then such an object
 * leaves the memory of the client by the ageing of the set, not at the moment the other module takes
 * the access away.
 *
 * The unified link is read as its stored state: a level written for the object that still grants
 * reading. The portal-wide default is deliberately not taken as a confirmation - every file of the
 * portal carries a unique code, so the default would confirm every row of every set and leave the
 * check with nothing to say.
 */
final class AccessSourceConfirmer implements AccessSourceConfirmerInterface
{
	/**
	 * The columns the visibility expression of the module is written over. The predicate is
	 * correlated, so the rows it runs on are narrowed by the identifiers of the page first.
	 */
	private const RIGHTS_COLUMNS = ['ID', 'CREATED_BY'];

	private ReaderCategory $reader;

	public function __construct(?ReaderCategory $reader = null)
	{
		$this->reader = $reader ?? new ReaderCategory();
	}

	/**
	 * @param array<int, array{
	 *     objectId: int,
	 *     realObjectId: int,
	 *     freshnessAt: DateTime,
	 *     attachedObjectId: int|null
	 * }> $rows
	 * @return array<int, array{
	 *     objectId: int,
	 *     realObjectId: int,
	 *     freshnessAt: DateTime,
	 *     attachedObjectId: int|null
	 * }>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function confirm(array $rows, int $userId): array
	{
		if ($rows === [])
		{
			return [];
		}

		$ids = self::identifiersOf($rows);
		$byRights = $this->visibleByDiskRights($ids, $userId);

		$beyondRights = $this->reader->allowsSourcesBeyondDiskRights($userId);
		$byLink = $beyondRights ? $this->carryingAnAliveLink($ids) : [];

		$confirmed = [];
		foreach ($rows as $row)
		{
			if (
				self::isAmong($row, $byRights)
				|| ($beyondRights && (self::isAmong($row, $byLink) || $row['attachedObjectId'] !== null))
			)
			{
				$confirmed[] = $row;
			}
		}

		return $confirmed;
	}

	/**
	 * Both identifiers of a row, and the answers are read by the same pair.
	 *
	 * The real object is the identity of a row - the set keeps it as a field of its own and the page
	 * is counted in real objects - but a link to it is an object with rights and links of its own,
	 * and the access of the user may well stand on the link rather than on the file behind it.
	 *
	 * @param array<int, array{objectId: int, realObjectId: int}> $rows
	 * @return int[]
	 */
	private static function identifiersOf(array $rows): array
	{
		$ids = [];
		foreach ($rows as $row)
		{
			$ids[$row['realObjectId']] = true;
			$ids[$row['objectId']] = true;
		}

		return array_keys($ids);
	}

	/**
	 * @param array{objectId: int, realObjectId: int} $row
	 * @param array<int, true> $ids
	 */
	private static function isAmong(array $row, array $ids): bool
	{
		return isset($ids[$row['realObjectId']]) || isset($ids[$row['objectId']]);
	}

	/**
	 * The one personal source of the three, asked on its own. A caller that holds objects instead of
	 * rows of the set has nothing else to ask: the other two answer for whoever holds a link or an
	 * attachment, and over an object nobody touched they would confirm it to every reader of the
	 * portal.
	 *
	 * @param int[] $objectIds
	 * @return array<int, true>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function visibleByDiskRights(array $objectIds, int $userId): array
	{
		if ($objectIds === [])
		{
			return [];
		}

		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			new DiskSecurityContext($userId),
			[
				'select' => ['ID'],
				'filter' => Query::filter()->whereIn('ID', $objectIds),
			],
			self::RIGHTS_COLUMNS,
		);

		$visible = [];
		$cursor = ObjectTable::getList($parameters);
		while ($row = $cursor->fetch())
		{
			$visible[(int)$row['ID']] = true;
		}

		return $visible;
	}

	/**
	 * The state of the links of the page, read by one query over its objects.
	 *
	 * The two kinds of link are joined to the objects rather than asked for one after another: what
	 * the caller needs is the objects at least one living link stands on, and that is one answer, not
	 * two. An object may carry many external links, so the rows are grouped back to one per object.
	 *
	 * @param int[] $ids
	 * @return array<int, true>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function carryingAnAliveLink(array $ids): array
	{
		$externalLink = new Reference(
			'SYNC_EXTERNAL_LINK',
			ExternalLinkTable::class,
			Join::on('this.ID', 'ref.OBJECT_ID'),
		);
		$externalLink->configureJoinType(Join::TYPE_LEFT);

		$unifiedLink = new Reference(
			'SYNC_UNIFIED_LINK',
			UnifiedLinkAccessTable::class,
			Join::on('this.ID', 'ref.OBJECT_ID'),
		);
		$unifiedLink->configureJoinType(Join::TYPE_LEFT);

		$rows = ObjectTable::query()
			->registerRuntimeField($externalLink)
			->registerRuntimeField($unifiedLink)
			->setSelect(['ID'])
			->whereIn('ID', $ids)
			->where(
				Query::filter()
					->logic('or')
					->where(
						Query::filter()
							->whereNotNull('SYNC_EXTERNAL_LINK.ID')
							->where(
								Query::filter()
									->logic('or')
									->whereNull('SYNC_EXTERNAL_LINK.DEATH_TIME')
									->where('SYNC_EXTERNAL_LINK.DEATH_TIME', '>', new DateTime())
								,
							)
						,
					)
					->where(
						Query::filter()
							->whereNotNull('SYNC_UNIFIED_LINK.ID')
							->whereNot('SYNC_UNIFIED_LINK.ACCESS_LEVEL', UnifiedLinkAccessLevel::Denied->value)
						,
					)
				,
			)
			->setGroup(['ID'])
			->fetchAll()
		;

		$carrying = [];
		foreach ($rows as $row)
		{
			$carrying[(int)$row['ID']] = true;
		}

		return $carrying;
	}
}
