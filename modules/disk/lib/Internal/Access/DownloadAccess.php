<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordService;
use Bitrix\Disk\Internals\ObjectOptionsTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class DownloadAccess
{
	protected const OBJECT_IDS_CHUNK_SIZE = 500;

	protected array $objectsOptionsCache = [];
	protected array $realObjectIdsByObjectIdCache = [];

	/**
	 * @param ExternalLinkProvider $externalLinkProvider
	 * @param ExternalLinkPasswordService $externalLinkPasswordService
	 */
	public function __construct(
		protected readonly ExternalLinkProvider $externalLinkProvider,
		protected readonly ExternalLinkPasswordService $externalLinkPasswordService,
	)
	{
	}

	/**
	 * @param BaseObject $object
	 * @param ExternalLink|null $externalLink
	 * @return bool
	 */
	public function checkAll(
		BaseObject $object,
		?ExternalLink $externalLink = null,
	): bool
	{
		return $this->canDownloadByObjectOption($object)
			|| $this->canDownloadByExternalLink($object, $externalLink);
	}

	public function checkObjectOption(BaseObject $object): bool
	{
		return $this->canDownloadByObjectOption($object);
	}

	public function checkObjectOptionByObjectId(int $objectId): bool
	{
		$realObjectId = $this->getRealObjectIdByObjectId($objectId);

		return $realObjectId > 0 && $this->canDownloadByObjectOptionId($realObjectId);
	}

	/**
	 * @param BaseObject $object
	 * @param ExternalLink|null $externalLink
	 * @return bool
	 */
	public function checkExternalLink(
		BaseObject $object,
		?ExternalLink $externalLink = null,
	): bool
	{
		return $this->canDownloadByExternalLink($object, $externalLink);
	}

	/**
	 * @param array<int, BaseObject> $objects
	 * @param ExternalLink|null $externalLink
	 * @return void
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function preload(array $objects, ?ExternalLink $externalLink = null): void
	{
		$objectIds = $this->getRealObjectIds($objects);

		$this->preloadByRealObjectIds($objectIds, $externalLink);
	}

	/**
	 * @param array<int, int> $objectIds
	 * @param ExternalLink|null $externalLink
	 * @return void
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function preloadByObjectIds(array $objectIds, ?ExternalLink $externalLink = null): void
	{
		$realObjectIds = $this->getRealObjectIdsByObjectIds($objectIds);

		$this->preloadByRealObjectIds($realObjectIds, $externalLink);
	}

	/**
	 * @param array<int, File> $files
	 * @return array<int, SecurityContext> Security contexts indexed by file object identity.
	 */
	public static function preloadForFilesBySecurityContext(array $files, int $userId): array
	{
		$groupsBySecurityContextKey = [];
		$securityContextsByFileId = [];

		foreach ($files as $file)
		{
			if (!$file instanceof File)
			{
				continue;
			}

			$securityContext = $file->getStorage()?->getSecurityContext($userId);

			if (!$securityContext instanceof SecurityContext)
			{
				continue;
			}

			$securityContextKey = self::getSecurityContextGroupKey($file, $securityContext);
			$groupsBySecurityContextKey[$securityContextKey]['securityContext'] ??= $securityContext;
			$groupsBySecurityContextKey[$securityContextKey]['files'][] = $file;
		}

		foreach ($groupsBySecurityContextKey as $group)
		{
			$securityContext = $group['securityContext'];
			$securityContextFiles = $group['files'];

			if ($securityContext instanceof DiskSecurityContext)
			{
				$securityContext->preloadOperationsForObjects($securityContextFiles);
			}

			foreach ($securityContextFiles as $file)
			{
				$securityContextsByFileId[spl_object_id($file)] = $securityContext;
			}
		}

		return $securityContextsByFileId;
	}

	public static function getSecurityContextGroupKey(
		BaseObject $object,
		SecurityContext $securityContext,
	): string
	{
		$storageId = (int)$object->getStorageId();
		if ($storageId <= 0)
		{
			return 'context:' . spl_object_id($securityContext);
		}

		return implode(':', [
			'storage',
			$storageId,
			get_class($securityContext),
			(string)$securityContext->getUserId(),
		]);
	}

	/**
	 * @param BaseObject $object
	 * @return bool
	 */
	protected function canDownloadByObjectOption(BaseObject $object): bool
	{
		$objectId = (int)$object->getRealObjectId();

		return $this->canDownloadByObjectOptionId($objectId);
	}

	protected function canDownloadByObjectOptionId(int $objectId): bool
	{
		if (!array_key_exists($objectId, $this->objectsOptionsCache))
		{
			$this->preloadObjectsOptions([$objectId]);
		}

		return $this->objectsOptionsCache[$objectId];
	}

	/**
	 * @param BaseObject $object
	 * @param ExternalLink|null $externalLink
	 * @return bool
	 */
	protected function canDownloadByExternalLink(
		BaseObject $object,
		?ExternalLink $externalLink = null,
	): bool
	{
		return $this->canDownloadByExternalLinkObjectId(
			(int)$object->getRealObjectId(),
			$externalLink,
		);
	}

	protected function canDownloadByExternalLinkObjectId(
		int $objectId,
		?ExternalLink $externalLink = null,
	): bool
	{
		$externalLink = $this->getExternalLinkByObjectId($objectId, $externalLink);

		if (!$externalLink instanceof ExternalLink || !$externalLink->isCanDownloadWithReadAccess())
		{
			return false;
		}

		if (!$externalLink->hasPassword())
		{
			return true;
		}

		return $this->externalLinkPasswordService->isConfirmed($externalLink);
	}

	protected function getExternalLinkByObjectId(int $objectId, ?ExternalLink $externalLink = null): ?ExternalLink
	{
		if ($externalLink instanceof ExternalLink)
		{
			return $externalLink;
		}

		return $this->externalLinkProvider->getForUnifiedLinkAccessCheck($objectId);
	}

	/**
	 * @param array<int, BaseObject> $objects
	 * @return array<int, int>
	 */
	protected function getRealObjectIds(array $objects): array
	{
		$objectIds = [];

		foreach ($objects as $object)
		{
			if ($object instanceof BaseObject)
			{
				$objectIds[] = (int)$object->getRealObjectId();
			}
		}

		return array_values(array_unique(array_filter($objectIds)));
	}

	/**
	 * @param array<int, int> $objectIds
	 * @return array<int, int>
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function getRealObjectIdsByObjectIds(array $objectIds): array
	{
		$objectIds = array_values(array_unique(array_filter(array_map('intval', $objectIds))));

		if (empty($objectIds))
		{
			return [];
		}

		$objectIdsToLoad = [];
		$realObjectIds = [];

		foreach ($objectIds as $objectId)
		{
			if (array_key_exists($objectId, $this->realObjectIdsByObjectIdCache))
			{
				$realObjectId = $this->realObjectIdsByObjectIdCache[$objectId];

				if ($realObjectId > 0)
				{
					$realObjectIds[] = $realObjectId;
				}

				continue;
			}

			$this->realObjectIdsByObjectIdCache[$objectId] = 0;
			$objectIdsToLoad[] = $objectId;
		}

		foreach (array_chunk($objectIdsToLoad, static::OBJECT_IDS_CHUNK_SIZE) as $chunk)
		{
			$items = ObjectTable::query()
				->setSelect(['ID', 'REAL_OBJECT_ID'])
				->whereIn('ID', $chunk)
				->exec()
			;

			while ($item = $items->fetch())
			{
				$objectId = (int)$item['ID'];
				$realObjectId = (int)$item['REAL_OBJECT_ID'];

				$this->realObjectIdsByObjectIdCache[$objectId] = $realObjectId;

				if ($realObjectId > 0)
				{
					$realObjectIds[] = $realObjectId;
				}
			}
		}

		return array_values(array_unique($realObjectIds));
	}

	protected function getRealObjectIdByObjectId(int $objectId): int
	{
		$realObjectIds = $this->getRealObjectIdsByObjectIds([$objectId]);

		return (int)($realObjectIds[0] ?? 0);
	}

	/**
	 * @param array<int, int> $objectIds
	 * @param ExternalLink|null $externalLink
	 * @return void
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function preloadByRealObjectIds(array $objectIds, ?ExternalLink $externalLink = null): void
	{
		$this->preloadObjectsOptions($objectIds);
		$this->preloadExternalLinks($objectIds, $externalLink);
	}

	/**
	 * @param array<int, int> $objectIds
	 * @return void
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function preloadObjectsOptions(array $objectIds): void
	{
		$objectIdsToLoad = [];

		foreach ($objectIds as $objectId)
		{
			if (!array_key_exists($objectId, $this->objectsOptionsCache))
			{
				$this->objectsOptionsCache[$objectId] = true;
				$objectIdsToLoad[] = $objectId;
			}
		}

		foreach ($this->loadObjectsOptions($objectIdsToLoad) as $item)
		{
			$objectId = (int)$item['OBJECT_ID'];

			if (!empty($item['VALUE']))
			{
				$this->objectsOptionsCache[$objectId] = json_decode($item['VALUE']) === true;
			}
		}
	}

	/**
	 * @param array<int, int> $objectIds
	 * @return iterable<array{OBJECT_ID: int|string, VALUE: string|null}>
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function loadObjectsOptions(array $objectIds): iterable
	{
		foreach (array_chunk($objectIds, static::OBJECT_IDS_CHUNK_SIZE) as $chunk)
		{
			$items = ObjectOptionsTable::query()
				->setSelect(['OBJECT_ID', 'VALUE'])
				->whereIn('OBJECT_ID', $chunk)
				->where('NAME', ObjectOptionsTable::NAME_ALLOW_DOWNLOAD_ON_READ)
				->exec()
			;

			while ($item = $items->fetch())
			{
				yield $item;
			}
		}
	}

	/**
	 * @param array<int, int> $objectIds
	 * @param ExternalLink|null $externalLink
	 * @return void
	 */
	protected function preloadExternalLinks(array $objectIds, ?ExternalLink $externalLink = null): void
	{
		if ($externalLink instanceof ExternalLink)
		{
			return;
		}

		foreach (array_chunk($objectIds, static::OBJECT_IDS_CHUNK_SIZE) as $chunk)
		{
			$this->externalLinkProvider->getForUnifiedLinkAccessCheckCollection($chunk);
		}
	}
}
