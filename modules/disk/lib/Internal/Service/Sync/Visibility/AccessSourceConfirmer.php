<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Visibility;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Model\UnifiedLinkAccessTable;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkLiveness;
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
 * Confirms the source of access behind a page of the interaction set (ALG-05).
 *
 * Presence in the set is not visibility: Disk rights may be revoked, a link may be switched off,
 * or the owning module may stop exposing its attached object. A row is retained while at least one
 * of these sources is current:
 *
 *  1. the object is visible by the rights of Disk;
 *  2. an external or unified link still grants reading;
 *  3. the attached object is readable according to its connector.
 *
 * Disk rights and links are checked in batches for the page. Attached objects are de-duplicated,
 * then checked one by one because their access belongs to the owning modules. Reader-category
 * narrowing remains in force: links and attachments are not considered for extranet and collab
 * readers ({@see ReaderCategory}).
 *
 * The portal-wide default unified-link level is deliberately not a confirmation. Every file has a
 * unique code, so that default would confirm every row and make revocation ineffective.
 */
final class AccessSourceConfirmer implements AccessSourceConfirmerInterface
{
	/**
	 * The columns the visibility expression of the module is written over. The predicate is
	 * correlated, so the rows it runs on are narrowed by the identifiers of the page first.
	 */
	private const RIGHTS_COLUMNS = ['ID', 'CREATED_BY'];

	private ReaderCategory $reader;
	private AttachedObjectAccessConfirmer $attachedObjectAccessConfirmer;

	public function __construct(
		?ReaderCategory $reader = null,
		?AttachedObjectAccessConfirmer $attachedObjectAccessConfirmer = null,
	)
	{
		$this->reader = $reader ?? new ReaderCategory();
		$this->attachedObjectAccessConfirmer = $attachedObjectAccessConfirmer ?? new AttachedObjectAccessConfirmer();
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
		$byAttachment = $beyondRights
			? $this->attachedObjectAccessConfirmer->readableIds($userId, self::attachedObjectIdsOf($rows))
			: [];

		$confirmed = [];
		foreach ($rows as $row)
		{
			if (
				self::isAmong($row, $byRights)
				|| ($beyondRights && self::isAmong($row, $byLink))
				|| ($row['attachedObjectId'] !== null && isset($byAttachment[$row['attachedObjectId']]))
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
	 * @param array<int, array{attachedObjectId: int|null}> $rows
	 * @return int[]
	 */
	private static function attachedObjectIdsOf(array $rows): array
	{
		$ids = [];
		foreach ($rows as $row)
		{
			if ($row['attachedObjectId'] !== null)
			{
				$ids[] = $row['attachedObjectId'];
			}
		}

		return $ids;
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
	 * The source asked on its own. A caller that holds objects instead of rows of the set has nothing
	 * else to ask: links and attachments are meaningful only in the context of a stored interaction.
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
					->where(ExternalLinkLiveness::aliveFilter('SYNC_EXTERNAL_LINK.'))
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
