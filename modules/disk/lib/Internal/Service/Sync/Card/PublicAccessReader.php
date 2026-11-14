<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Card;

use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkLiveness;
use Bitrix\Disk\Internal\Service\Sync\Access\PublicLinkRule;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * The public sources of a page of objects: alive public external links of every object and of every
 * one of its ancestors, read in one statement per batch over the closure of paths.
 *
 * A link counts when it is alive ({@see ExternalLinkLiveness}) and of the public kind
 * ({@see PublicLinkRule}); the policy of the portal is not read. The sources of an object come ordered
 * so that the first one is the effective source: the link on the object itself, then the links on its
 * versions (the one without a lifetime, then the one living longest, then the newest), then the links
 * of the ancestors from the nearest one up.
 */
final class PublicAccessReader
{
	private const IDS_PER_QUERY = 300;

	private const LINK = 'SYNC_PUBLIC_LINK';

	/**
	 * @param int[] $realObjectIds
	 * @return array<int, list<array{
	 *     sourceId: int,
	 *     depth: int,
	 *     linkId: int,
	 *     versionId: ?int,
	 *     accessRight: int,
	 *     deathTime: ?DateTime,
	 *     hasPassword: bool
	 * }>> keyed by the object; an object without public sources is absent
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function read(array $realObjectIds): array
	{
		$realObjectIds = array_values(array_unique(array_filter(array_map('intval', $realObjectIds))));
		if ($realObjectIds === [])
		{
			return [];
		}

		$sources = [];
		foreach (array_chunk($realObjectIds, self::IDS_PER_QUERY) as $chunk)
		{
			foreach ($this->readChunk($chunk) as $row)
			{
				if (!PublicLinkRule::isPublicKind((int)$row['LINK_TYPE'], self::nullableString($row['LINK_SCENARIO'])))
				{
					continue;
				}

				$versionId = (int)$row['LINK_VERSION_ID'];
				$deathTime = $row['LINK_DEATH_TIME'] instanceof DateTime ? $row['LINK_DEATH_TIME'] : null;

				$sources[(int)$row['OBJECT_ID']][] = [
					'sourceId' => (int)$row['PARENT_ID'],
					'depth' => (int)$row['DEPTH_LEVEL'],
					'linkId' => (int)$row['LINK_ID'],
					'versionId' => $versionId > 0 ? $versionId : null,
					'accessRight' => (int)$row['LINK_ACCESS_RIGHT'],
					'deathTime' => $deathTime,
					'hasPassword' => (bool)$row['LINK_HAS_PASSWORD'],
				];
			}
		}

		foreach ($sources as &$objectSources)
		{
			usort($objectSources, self::compare(...));
		}
		unset($objectSources);

		return $sources;
	}

	/**
	 * @param int[] $objectIds
	 * @return array<int, array<string, mixed>>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readChunk(array $objectIds): array
	{
		$link = new Reference(
			self::LINK,
			ExternalLinkTable::class,
			Join::on('this.PARENT_ID', 'ref.OBJECT_ID'),
		);
		$link->configureJoinType(Join::TYPE_INNER);

		return ObjectPathTable::query()
			->registerRuntimeField($link)
			->registerRuntimeField(new ExpressionField(
				'LINK_HAS_PASSWORD',
				"CASE WHEN %s IS NULL OR %s = '' THEN 0 ELSE 1 END",
				[self::LINK . '.PASSWORD', self::LINK . '.PASSWORD'],
			))
			->setSelect([
				'OBJECT_ID',
				'PARENT_ID',
				'DEPTH_LEVEL',
				'LINK_ID' => self::LINK . '.ID',
				'LINK_VERSION_ID' => self::LINK . '.VERSION_ID',
				'LINK_ACCESS_RIGHT' => self::LINK . '.ACCESS_RIGHT',
				'LINK_DEATH_TIME' => self::LINK . '.DEATH_TIME',
				'LINK_TYPE' => self::LINK . '.TYPE',
				'LINK_SCENARIO' => self::LINK . '.SCENARIO',
				'LINK_HAS_PASSWORD',
			])
			->whereIn('OBJECT_ID', $objectIds)
			->where(ExternalLinkLiveness::aliveFilter(self::LINK . '.'))
			->where(PublicLinkRule::publicKindFilter(self::LINK . '.'))
			->fetchAll()
		;
	}

	/**
	 * @param array{depth: int, linkId: int, versionId: ?int, deathTime: ?DateTime} $a
	 * @param array{depth: int, linkId: int, versionId: ?int, deathTime: ?DateTime} $b
	 */
	private static function compare(array $a, array $b): int
	{
		return
			self::rankOf($a) <=> self::rankOf($b)
			?: $a['depth'] <=> $b['depth']
			?: self::lifetimeOrder($a, $b)
			?: $b['linkId'] <=> $a['linkId']
		;
	}

	/**
	 * The object link first, then the version links, then the inherited ones.
	 *
	 * @param array{depth: int, versionId: ?int} $source
	 */
	private static function rankOf(array $source): int
	{
		if ($source['depth'] > 0)
		{
			return 2;
		}

		return $source['versionId'] === null ? 0 : 1;
	}

	/**
	 * Among version links the one keeping the file open longest goes first: no lifetime, then the
	 * latest death time.
	 *
	 * @param array{depth: int, versionId: ?int, deathTime: ?DateTime} $a
	 * @param array{depth: int, versionId: ?int, deathTime: ?DateTime} $b
	 */
	private static function lifetimeOrder(array $a, array $b): int
	{
		if (self::rankOf($a) !== 1)
		{
			return 0;
		}

		$aEnd = $a['deathTime']?->getTimestamp() ?? PHP_INT_MAX;
		$bEnd = $b['deathTime']?->getTimestamp() ?? PHP_INT_MAX;

		return $bEnd <=> $aEnd;
	}

	private static function nullableString(mixed $value): ?string
	{
		return $value === null || $value === '' ? null : (string)$value;
	}
}
