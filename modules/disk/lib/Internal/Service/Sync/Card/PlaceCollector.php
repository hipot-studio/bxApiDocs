<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Card;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\PlaceDto;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;

/**
 * The places of a page of objects (ALG-04).
 *
 * The algorithm is the one the desktop client already runs - the closure table instead of a walk of
 * the tree, whole pages read at a time, a deterministic order - generalised in the two ways the
 * contour needs.
 *
 * The first generalisation is "one path" into "a list of places". The legacy resolver answers where
 * an object is; a client that keeps its own copy needs every place it is, because a file shared
 * twice is one file in two folders and a restore from the trashcan has to know which of them it
 * concerns. The search is driven from the storages the user sees: the list of them is cheap, and it
 * turns "find the links" into "find the links in these storages", which is a condition the database
 * applies rather than a filter over rows already read.
 *
 * The second is "close into refusal" into "cut and say so". Where the legacy resolver meets an
 * ancestor the user may not read it drops the object entirely, and the comment in that code names
 * the reason: the name of a foreign folder must not leak. The contour still has to hand the object
 * over, so the path is cut at the boundary instead - {@see PathTrimmer} - and the answer says the
 * path is not whole. What does not change is that nothing above the boundary is published: the name
 * of an unreadable folder is not even read out of the database, the query replaces it with null.
 *
 * Every step is batched. The number of queries per page is not constant, and cannot be: how many
 * rounds the search of links takes depends on how deeply links are nested in one another, not on how
 * many objects were asked about.
 */
final class PlaceCollector
{
	/**
	 * How many identifiers one statement carries. The chain of a page of objects and the links found
	 * over it both grow with the sharing of the portal rather than with the size of the page, so the
	 * lists are cut into statements of a size the database plans well instead of being handed over
	 * whole.
	 */
	private const IDS_PER_QUERY = 300;

	private PathTrimmer $trimmer;

	public function __construct(?PathTrimmer $trimmer = null)
	{
		$this->trimmer = $trimmer ?? new PathTrimmer();
	}

	/**
	 * @param int[] $realObjectIds real objects of the page, as the fetch collapsed them
	 * @param int[] $visibleStorageIds storages the user sees, from
	 *        {@see \Bitrix\Disk\Internal\Service\Sync\StorageListProvider}
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function collect(int $userId, array $realObjectIds, array $visibleStorageIds): PlaceSet
	{
		$realObjectIds = self::normaliseIds($realObjectIds);
		$visibleStorageIds = self::normaliseIds($visibleStorageIds);

		if ($realObjectIds === [])
		{
			return PlaceSet::createEmpty();
		}

		if ($userId <= 0 || $visibleStorageIds === [])
		{
			// A user who sees no storage has nowhere to see an object: the objects of the page are
			// still theirs to synchronise, and every one of them is simply without a place.
			return PlaceSet::create(array_fill_keys($realObjectIds, []));
		}

		$graph = $this->loadGraph($userId, $realObjectIds, $visibleStorageIds);

		$memo = [];
		$inProgress = [];
		$places = [];
		foreach ($realObjectIds as $realObjectId)
		{
			$places[$realObjectId] = $this->buildPlaces($realObjectId, $graph, $memo, $inProgress);
		}

		return PlaceSet::create($places);
	}

	/**
	 * The rows and links the resolution runs over, read in rounds.
	 *
	 * One round reads the chains of what it was given and then the links pointing at anything in
	 * those chains. A link found this way is a folder of the user, and the round after it reads where
	 * that folder itself lies - which is how a link nested inside another link is reached. The rounds
	 * stop when one of them finds no link that was not already known, and they always stop: every
	 * round consumes rows of a finite table and never returns to one.
	 *
	 * @param int[] $seedIds
	 * @param int[] $visibleStorageIds
	 * @return array{
	 *     rows: array<int, array{id: int, parentId: int|null, storageId: int, deletedType: int, name: string|null}>,
	 *     ancestors: array<int, array<int, array{id: int, name: string|null, parentId: int|null, storageId: int}>>,
	 *     linksByTarget: array<int, int[]>,
	 *     visibleStorageIds: array<int, true>
	 * }
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function loadGraph(int $userId, array $seedIds, array $visibleStorageIds): array
	{
		$rows = [];
		$ancestors = [];
		$linksByTarget = [];
		$askedForLinks = [];

		$frontier = $seedIds;
		while ($frontier !== [])
		{
			$chains = $this->readChains($userId, $frontier);
			foreach ($chains['rows'] as $objectId => $row)
			{
				$rows[$objectId] ??= $row;
			}
			foreach ($chains['ancestors'] as $objectId => $chain)
			{
				$ancestors[$objectId] = $chain;
			}

			$targets = $frontier;
			foreach ($chains['ancestors'] as $chain)
			{
				foreach ($chain as $ancestor)
				{
					$targets[] = $ancestor['id'];
				}
			}

			$targets = array_values(array_diff(array_unique($targets), array_keys($askedForLinks)));
			$askedForLinks += array_fill_keys($targets, true);

			$frontier = [];
			foreach ($this->readLinks($userId, $targets, $visibleStorageIds) as $link)
			{
				// "This row is already loaded" and "this link is already counted as a way to its
				// target" are two different facts, and one guard over both loses the second. A link
				// asked about beside its own target is a row of the graph before the search for links
				// ever runs, and the target would then be left without the place that link opens.
				$linksByTarget[$link['realObjectId']][$link['id']] = $link['id'];

				if (isset($rows[$link['id']]))
				{
					continue;
				}

				$rows[$link['id']] = $link;
				$frontier[] = $link['id'];
			}

			sort($frontier);
		}

		// Sorted into the order the places are answered in, and keyed by the link until here so that a
		// link met twice over the rounds is one way in and not two.
		foreach ($linksByTarget as &$linkIds)
		{
			sort($linkIds);
		}
		unset($linkIds);

		return [
			'rows' => $rows,
			'ancestors' => $ancestors,
			'linksByTarget' => $linksByTarget,
			'visibleStorageIds' => array_fill_keys($visibleStorageIds, true),
		];
	}

	/**
	 * The chain of every given object, read from the closure table in one statement per batch.
	 *
	 * The name of an ancestor is replaced with null by the query itself when the user may not read
	 * it. Withholding the name in the database and not in the code is the point: a name that never
	 * leaves the database cannot leak through a field nobody thought about.
	 *
	 * @param int[] $objectIds
	 * @return array{
	 *     rows: array<int, array{id: int, parentId: int|null, storageId: int, deletedType: int, name: string|null}>,
	 *     ancestors: array<int, array<int, array{
	 *         id: int, name: string|null, parentId: int|null, storageId: int, depth: int
	 *     }>>
	 * }
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readChains(int $userId, array $objectIds): array
	{
		$rows = [];
		$ancestors = [];

		$readableName = new ExpressionField(
			'READABLE_NAME',
			'CASE WHEN ' . (new DiskSecurityContext($userId))->getSqlExpressionForList('%1$s', '%2$s')
			. ' THEN %3$s ELSE NULL END',
			['ID', 'CREATED_BY', 'NAME'],
			['data_type' => 'string'],
		);

		foreach (array_chunk($objectIds, self::IDS_PER_QUERY) as $chunk)
		{
			$cursor = ObjectTable::getList([
				'select' => [
					'ID',
					'PARENT_ID',
					'STORAGE_ID',
					'DELETED_TYPE',
					'READABLE_NAME',
					'DESCENDANT_ID' => 'PATH_PARENT.OBJECT_ID',
					'DEPTH' => 'PATH_PARENT.DEPTH_LEVEL',
				],
				'filter' => Query::filter()->whereIn('PATH_PARENT.OBJECT_ID', $chunk),
				'runtime' => [$readableName],
			]);

			while ($row = $cursor->fetch())
			{
				$descendantId = (int)$row['DESCENDANT_ID'];
				$node = [
					'id' => (int)$row['ID'],
					'name' => $row['READABLE_NAME'] === null ? null : (string)$row['READABLE_NAME'],
					'parentId' => $row['PARENT_ID'] === null ? null : (int)$row['PARENT_ID'],
					'storageId' => (int)$row['STORAGE_ID'],
				];

				if ((int)$row['DEPTH'] === 0)
				{
					$rows[$descendantId] = $node + ['deletedType' => (int)$row['DELETED_TYPE']];

					continue;
				}

				$ancestors[$descendantId][] = $node + ['depth' => (int)$row['DEPTH']];
			}
		}

		foreach ($ancestors as &$chain)
		{
			// Ordered here rather than by the database: an order over the joined closure table costs a
			// sort of the whole result, while a chain is a handful of rows and its order matters only
			// within one object.
			usort($chain, static fn(array $left, array $right): int => $left['depth'] <=> $right['depth']);
		}
		unset($chain);

		foreach ($objectIds as $objectId)
		{
			$ancestors[$objectId] ??= [];
		}

		return ['rows' => $rows, 'ancestors' => $ancestors];
	}

	/**
	 * The links of the user pointing at any of the given objects.
	 *
	 * Narrowed to the storages the user sees, which is what keeps the search of links bounded: the
	 * rows of everyone else are refused by the condition instead of being read and then filtered. A
	 * link the user may not read is no way in either, so the rights of the module decide the rows
	 * here as they decide every other list.
	 *
	 * @param int[] $targetIds
	 * @param int[] $visibleStorageIds
	 * @return array<int, array{
	 *     id: int, name: string|null, parentId: int|null, storageId: int, deletedType: int, realObjectId: int
	 * }>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readLinks(int $userId, array $targetIds, array $visibleStorageIds): array
	{
		if ($targetIds === [])
		{
			return [];
		}

		$links = [];
		foreach (array_chunk($targetIds, self::IDS_PER_QUERY) as $chunk)
		{
			$parameters = [
				'select' => ['ID', 'NAME', 'PARENT_ID', 'STORAGE_ID', 'DELETED_TYPE', 'REAL_OBJECT_ID'],
				'filter' => Query::filter()
					->whereIn('REAL_OBJECT_ID', $chunk)
					->whereIn('STORAGE_ID', $visibleStorageIds)
					->whereColumn('REAL_OBJECT_ID', '!=', 'ID')
				,
			];
			$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
				new DiskSecurityContext($userId),
				$parameters,
				['ID', 'CREATED_BY'],
			);

			$cursor = ObjectTable::getList($parameters);
			while ($row = $cursor->fetch())
			{
				$links[] = [
					'id' => (int)$row['ID'],
					'name' => (string)$row['NAME'],
					'parentId' => $row['PARENT_ID'] === null ? null : (int)$row['PARENT_ID'],
					'storageId' => (int)$row['STORAGE_ID'],
					'deletedType' => (int)$row['DELETED_TYPE'],
					'realObjectId' => (int)$row['REAL_OBJECT_ID'],
				];
			}
		}

		return $links;
	}

	/**
	 * The places of one real object.
	 *
	 * An object is looked for at every row that stands for it in a storage of the user - its own row
	 * and every link pointing at it - and the row decides the state of the place it produces. This is
	 * what the trashcan of a foreign storage rests on: the deletion writes a link of its own into the
	 * storage of whoever deleted it, and that link carries its own name and its own kind of deletion
	 * while the object itself is untouched.
	 *
	 * A row that is a link to the object is a way in through that link, whatever the route above the
	 * row turns out to be: the place it makes is reached by a link even where the link itself lies at
	 * the top of a storage of the user.
	 *
	 * @param array<string, mixed> $graph
	 * @param array<int, array<int, array<string, mixed>>> $memo
	 * @param array<int, true> $inProgress
	 * @return PlaceDto[]
	 */
	private function buildPlaces(int $realObjectId, array $graph, array &$memo, array &$inProgress): array
	{
		$rowIds = array_merge([$realObjectId], $graph['linksByTarget'][$realObjectId] ?? []);

		$places = [];
		foreach ($rowIds as $rowId)
		{
			$row = $graph['rows'][$rowId] ?? null;
			if ($row === null)
			{
				continue;
			}

			foreach ($this->routesTo($rowId, $graph, $memo, $inProgress) as $route)
			{
				$trimmed = $this->trimmer->trim($route['chain']);

				$place = new PlaceDto();
				$place->storageId = $route['storageId'];
				$place->parentFolderId = $route['parentFolderId'];
				$place->path = $trimmed['path'];
				$place->pathComplete = $trimmed['complete'];
				$place->reachedByLink = $route['reachedByLink'] || $rowId !== $realObjectId;
				$place->trashed = $row['deletedType'] !== ObjectTable::DELETED_TYPE_NONE;
				$place->trashedWithAncestor = $row['deletedType'] === ObjectTable::DELETED_TYPE_CHILD;

				$signature = self::signatureOf($place);
				$kept = $places[$signature] ?? null;
				if ($kept === null)
				{
					$places[$signature] = $place;

					continue;
				}

				// One place met twice keeps the plainer way in: an object a folder leads to is not
				// reached by a link just because a link leads to the very same place as well.
				$kept->reachedByLink = $kept->reachedByLink && $place->reachedByLink;
			}
		}

		ksort($places);

		return array_values($places);
	}

	/**
	 * Every way the user reaches the folder the given row lies in.
	 *
	 * The chain is climbed from the row upward. An ancestor that some link of the user points at is a
	 * way in of its own, and the folders already passed hang below it; the top of a storage the user
	 * sees is a way in as well. The climb stops at the first ancestor the user may not read - above
	 * that boundary there is nothing to walk to, and a way in found higher would describe a route the
	 * user cannot take. That ancestor still enters the chain, as a folder without a name: it is what
	 * tells {@see PathTrimmer} where to cut and what makes the answer say the path is not whole, and
	 * it travels with the chain when a longer route is built on top of it.
	 *
	 * A route carries whether it runs through a link at all. The top of a storage and the boundary of
	 * access are reached by walking folders; everything built over a link is reached by that link, and
	 * so is everything built over such a route in turn.
	 *
	 * Results are memoised per row and guarded against a cycle of links pointing at one another; a
	 * route that closes on itself is no route and contributes nothing.
	 *
	 * @param array<string, mixed> $graph
	 * @param array<int, array<int, array<string, mixed>>> $memo
	 * @param array<int, true> $inProgress
	 * @return array<int, array{
	 *     storageId: int,
	 *     parentFolderId: int,
	 *     reachedByLink: bool,
	 *     chain: array<int, array{id: int, name: string|null}>
	 * }>
	 */
	private function routesTo(int $rowId, array $graph, array &$memo, array &$inProgress): array
	{
		if (isset($memo[$rowId]))
		{
			return $memo[$rowId];
		}
		if (isset($inProgress[$rowId]))
		{
			return [];
		}

		$row = $graph['rows'][$rowId] ?? null;
		if ($row === null || $row['parentId'] === null)
		{
			return $memo[$rowId] = [];
		}

		$inProgress[$rowId] = true;

		$routes = [];
		$passed = [];
		foreach ($graph['ancestors'][$rowId] ?? [] as $ancestor)
		{
			foreach ($graph['linksByTarget'][$ancestor['id']] ?? [] as $linkId)
			{
				$link = $graph['rows'][$linkId];
				foreach ($this->routesTo($linkId, $graph, $memo, $inProgress) as $under)
				{
					$routes[] = [
						'storageId' => $under['storageId'],
						'parentFolderId' => $passed === [] ? $link['id'] : $row['parentId'],
						'reachedByLink' => true,
						'chain' => array_merge(
							$under['chain'],
							[['id' => $link['id'], 'name' => $link['name']]],
							$passed,
						),
					];
				}
			}

			if ($ancestor['parentId'] === null)
			{
				if (isset($graph['visibleStorageIds'][$ancestor['storageId']]))
				{
					$routes[] = [
						'storageId' => $ancestor['storageId'],
						'parentFolderId' => $row['parentId'],
						'reachedByLink' => false,
						'chain' => $passed,
					];
				}

				break;
			}

			if ($ancestor['name'] === null)
			{
				if (isset($graph['visibleStorageIds'][$row['storageId']]))
				{
					$routes[] = [
						'storageId' => $row['storageId'],
						'parentFolderId' => $row['parentId'],
						'reachedByLink' => false,
						'chain' => array_merge([['id' => $ancestor['id'], 'name' => null]], $passed),
					];
				}

				break;
			}

			array_unshift($passed, ['id' => $ancestor['id'], 'name' => $ancestor['name']]);
		}

		unset($inProgress[$rowId]);

		return $memo[$rowId] = $routes;
	}

	/**
	 * What makes two places the same place for the client, and the order they are answered in.
	 *
	 * Two rows can describe one and the same place - a link of the trashcan beside the object it was
	 * made from - and the client has no use for the second copy. Two shares of one folder, on the
	 * other hand, differ in the node they hang from and stay two places.
	 *
	 * Whether the place was reached by a link is left out on purpose: it says how the place was
	 * arrived at and not which place it is, so two ways to one place stay one place and their answers
	 * about the way in are collapsed instead of standing beside each other.
	 */
	private static function signatureOf(PlaceDto $place): string
	{
		$nodes = array_map(static fn($node): string => self::sortable($node->id), $place->path);

		return implode(':', [
			self::sortable($place->storageId),
			self::sortable($place->parentFolderId),
			implode('/', $nodes),
			(int)$place->pathComplete,
			(int)$place->trashed,
			(int)$place->trashedWithAncestor,
		]);
	}

	/** An identifier written so that the order of the signatures as text is the order of the numbers. */
	private static function sortable(int $id): string
	{
		return str_pad((string)$id, 11, '0', STR_PAD_LEFT);
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function normaliseIds(array $ids): array
	{
		$normalised = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalised[$id] = $id;
			}
		}

		return array_values($normalised);
	}
}
