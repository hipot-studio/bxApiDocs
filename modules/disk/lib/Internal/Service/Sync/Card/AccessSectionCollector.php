<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Card;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\AccessSectionDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ObjectCardDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\PublicAccessDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\SharingDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\SharingTargetDto;
use Bitrix\Disk\Internal\Service\Sync\Visibility\AccessSourceConfirmer;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;

/**
 * The section of access of the cards of a page, assembled in a fixed number of statements.
 *
 * What is shown follows what the interface of Disk shows the reader. The flags of internal sharing,
 * the fact of public access, its kind and whether other sources exist reach every reader. The folder
 * a public access is inherited from and the parameters of its link are named only when the reader
 * reads that folder and every folder between it and the object; otherwise the fact comes alone. The
 * targets of the rights come whole or not at all: to whoever may change the rights of the object,
 * and to whoever may only share it when every target comes from sharing.
 */
final class AccessSectionCollector
{
	private const IDS_PER_QUERY = 300;

	private PublicAccessReader $publicAccess;
	private SharingTargetReader $sharingTargets;
	private AccessSourceConfirmer $sourceConfirmer;

	public function __construct(
		?PublicAccessReader $publicAccess = null,
		?SharingTargetReader $sharingTargets = null,
		?AccessSourceConfirmer $sourceConfirmer = null,
	)
	{
		$this->publicAccess = $publicAccess ?? new PublicAccessReader();
		$this->sharingTargets = $sharingTargets ?? new SharingTargetReader();
		$this->sourceConfirmer = $sourceConfirmer ?? new AccessSourceConfirmer();
	}

	/**
	 * A row of a link - a folder of somebody else connected to the Disk of the reader - is described by
	 * the object behind it: the rights and the links live there, the row of the link carries neither.
	 *
	 * Only objects visible to the reader are given here: canRead = true relies on it and the rights are
	 * not checked again. Visibility is decided by the caller: the journal by confirming it
	 * ({@see \Bitrix\Disk\Internal\Service\Sync\Journal\JournalReader}), the state by its verdict,
	 * the snapshot by its fetch, by the readable ancestors and checkpoints and by the nodes of the paths
	 * cut at the boundary of access.
	 *
	 * @param array<int, array<string, mixed>> $rows rows of the objects of the page visible to the
	 *        reader, with CARD_TYPE and an optional REAL_OBJECT_ID, keyed by the object; without
	 *        REAL_OBJECT_ID (null or 0) the object is described by itself
	 * @param array<int, true> $availableFileIds files whose content the reader may fetch
	 * @return array<int, AccessSectionDto> keyed by the object
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function collect(int $userId, array $rows, array $availableFileIds): array
	{
		$realObjectIds = [];
		foreach ($rows as $objectId => $row)
		{
			$realObjectIds[(int)$objectId] = (int)($row['REAL_OBJECT_ID'] ?? 0) ?: (int)$objectId;
		}
		if ($realObjectIds === [])
		{
			return [];
		}

		$distinctRealObjectIds = array_values(array_unique($realObjectIds));
		$publicSources = $this->publicAccess->read($distinctRealObjectIds);
		$sharing = $this->sharingTargets->read($distinctRealObjectIds);

		$security = new DiskSecurityContext($userId);
		$security->preloadOperationsForObjectIds($distinctRealObjectIds);

		$namedSources = $this->findNamedSources($userId, $publicSources);

		$sections = [];
		foreach ($rows as $objectId => $row)
		{
			$objectId = (int)$objectId;
			$realObjectId = $realObjectIds[$objectId];

			$section = new AccessSectionDto();
			$section->canRead = true;
			if ($row['CARD_TYPE'] === ObjectCardDto::TYPE_FILE)
			{
				$section->canDownload = isset($availableFileIds[$objectId]);
			}
			$section->internalSharing = self::sharingOf(
				$sharing[$realObjectId] ?? null,
				(bool)$security->canChangeRights($realObjectId),
				(bool)$security->canShare($realObjectId),
			);
			$section->publicAccess = self::publicAccessOf(
				$publicSources[$realObjectId] ?? [],
				isset($namedSources[$realObjectId]),
			);

			$sections[$objectId] = $section;
		}

		return $sections;
	}

	/**
	 * The objects whose effective source is inherited from a folder the reader may name: the reader
	 * reads the folder and every real ancestor of the object between it and the folder.
	 *
	 * @param array<int, list<array{sourceId: int, depth: int}>> $publicSources
	 * @return array<int, true>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function findNamedSources(int $userId, array $publicSources): array
	{
		$sourceDepths = [];
		foreach ($publicSources as $objectId => $sources)
		{
			if ($sources !== [] && $sources[0]['depth'] > 0)
			{
				$sourceDepths[$objectId] = $sources[0]['depth'];
			}
		}
		if ($sourceDepths === [])
		{
			return [];
		}

		$chains = $this->readChains($sourceDepths);

		$folderIds = [];
		foreach ($chains as $chain)
		{
			foreach ($chain as $folderId)
			{
				$folderIds[$folderId] = $folderId;
			}
		}
		$readable = $this->sourceConfirmer->visibleByDiskRights(array_values($folderIds), $userId);

		$named = [];
		foreach ($sourceDepths as $objectId => $depth)
		{
			$chain = $chains[$objectId] ?? [];
			if (count($chain) !== $depth)
			{
				continue;
			}

			foreach ($chain as $folderId)
			{
				if (!isset($readable[$folderId]))
				{
					continue 2;
				}
			}

			$named[$objectId] = true;
		}

		return $named;
	}

	/**
	 * The real ancestors of every object up to the depth of its source, the source included.
	 *
	 * @param array<int, int> $sourceDepths depth of the effective source, keyed by the object
	 * @return array<int, int[]> keyed by the object
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readChains(array $sourceDepths): array
	{
		$maxDepth = max($sourceDepths);

		$chains = [];
		foreach (array_chunk(array_keys($sourceDepths), self::IDS_PER_QUERY) as $chunk)
		{
			$rows = ObjectPathTable::query()
				->setSelect(['OBJECT_ID', 'PARENT_ID', 'DEPTH_LEVEL'])
				->whereIn('OBJECT_ID', $chunk)
				->where(Query::filter()->where('DEPTH_LEVEL', '>', 0)->where('DEPTH_LEVEL', '<=', $maxDepth))
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$objectId = (int)$row['OBJECT_ID'];
				if ((int)$row['DEPTH_LEVEL'] <= $sourceDepths[$objectId])
				{
					$chains[$objectId][] = (int)$row['PARENT_ID'];
				}
			}
		}

		return $chains;
	}

	/**
	 * @param array{direct: list<array{type: string, id: ?int, bySharing: bool}>, inherited: bool}|null $sharing
	 */
	private static function sharingOf(?array $sharing, bool $canChangeRights, bool $canShare): SharingDto
	{
		$direct = $sharing['direct'] ?? [];

		$dto = new SharingDto();
		$dto->enabled = $direct !== [];
		$dto->inherited = $sharing['inherited'] ?? false;

		$allFromSharing = array_filter($direct, static fn(array $target): bool => !$target['bySharing']) === [];
		if ($canChangeRights || ($canShare && $allFromSharing))
		{
			$dto->targets = array_map(
				static fn(array $target): SharingTargetDto => SharingTargetDto::of($target['type'], $target['id']),
				$direct,
			);
		}

		return $dto;
	}

	/**
	 * @param list<array{
	 *     sourceId: int,
	 *     depth: int,
	 *     accessRight: int,
	 *     deathTime: ?\Bitrix\Main\Type\DateTime,
	 *     hasPassword: bool
	 * }> $sources ordered, the effective source first
	 */
	private static function publicAccessOf(array $sources, bool $sourceIsNamed): PublicAccessDto
	{
		if ($sources === [])
		{
			return PublicAccessDto::disabled();
		}

		$effective = $sources[0];
		$hasOtherSources = count($sources) > 1;
		$inherited = $effective['depth'] > 0;

		if ($inherited && !$sourceIsNamed)
		{
			return PublicAccessDto::hidden($hasOtherSources);
		}

		return PublicAccessDto::visible(
			$inherited ? PublicAccessDto::SOURCE_INHERITED : PublicAccessDto::SOURCE_DIRECT,
			$inherited ? $effective['sourceId'] : null,
			$effective['accessRight'] === ExternalLinkTable::ACCESS_RIGHT_EDIT
				? PublicAccessDto::PERMISSION_EDIT
				: PublicAccessDto::PERMISSION_VIEW,
			$effective['deathTime'],
			$effective['hasPassword'],
			$hasOtherSources,
		);
	}
}
