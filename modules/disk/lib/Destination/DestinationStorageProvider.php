<?php

declare(strict_types=1);

namespace Bitrix\Disk\Destination;

use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Service\Storage\AvailableStorageService;
use Bitrix\Disk\Internal\Service\Storage\StorageType;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Storage;

/**
 * Builds the list of storages a user may pick as a destination.
 *
 * A storage the user cannot write to is dropped from the list; a storage closed by the tariff stays in it
 * and carries a filled restriction instead.
 */
class DestinationStorageProvider
{
	public const SECTION_USER = 'user';
	public const SECTION_COMMON = 'common';
	public const SECTION_GROUP = 'group';
	public const SECTION_COLLAB = 'collab';

	private const SECTION_ORDER = [
		self::SECTION_USER,
		self::SECTION_COMMON,
		self::SECTION_GROUP,
		self::SECTION_COLLAB,
	];

	private readonly AvailableStorageService $availableStorageService;
	private readonly StorageTariffRestriction $tariffRestriction;

	public function __construct(
		?AvailableStorageService $availableStorageService = null,
		?StorageTariffRestriction $tariffRestriction = null,
	)
	{
		$this->availableStorageService = $availableStorageService ?? new AvailableStorageService();
		$this->tariffRestriction = $tariffRestriction ?? new StorageTariffRestriction();
	}

	/**
	 * @return array<int, array{type: string, storages: array<int, array>}>
	 */
	public function getGroupedStorages(int $userId): array
	{
		$storages = $this->getWritableStorages($userId);
		$groupTitles = $this->availableStorageService->loadGroupTitlesForStorages($storages);

		$sections = [];
		foreach ($storages as $storage)
		{
			$type = $this->availableStorageService->resolveType($storage);
			if ($type === null)
			{
				continue;
			}

			$descriptor = $this->normalizeStorage($storage, $type, $groupTitles);
			if ($descriptor === null)
			{
				continue;
			}

			$sections[$this->resolveSection($type)][] = $descriptor;
		}

		$groups = [];
		foreach (self::SECTION_ORDER as $section)
		{
			if (!empty($sections[$section]))
			{
				$groups[] = [
					'type' => $section,
					'storages' => $sections[$section],
				];
			}
		}

		return $groups;
	}

	/**
	 * @return Storage[]
	 */
	protected function getWritableStorages(int $userId): array
	{
		$writableStorages = [];
		foreach ($this->availableStorageService->getReadableStorages($userId) as $storage)
		{
			if ($this->canWriteToRootObject($storage, $userId))
			{
				$writableStorages[] = $storage;
			}
		}

		return $writableStorages;
	}

	protected function getSecurityContext(Storage $storage, int $userId): SecurityContext
	{
		return $storage->getSecurityContext($userId);
	}

	private function canWriteToRootObject(Storage $storage, int $userId): bool
	{
		$rootObject = $storage->getRootObject();

		return $rootObject instanceof Folder
			&& $rootObject->canAdd($this->getSecurityContext($storage, $userId));
	}

	private function normalizeStorage(Storage $storage, StorageType $type, array $groupTitles): ?array
	{
		$storageId = (int)$storage->getId();
		$rootFolderId = (int)($storage->getRootObject()?->getId() ?? 0);
		if ($storageId <= 0 || $rootFolderId <= 0)
		{
			return null;
		}

		return [
			'id' => $storageId,
			'rootFolderId' => $rootFolderId,
			'name' => $this->availableStorageService->resolveSourceTitle($storage, $groupTitles),
			'type' => $type->value,
			'avatar' => $this->normalizeAvatar($storage),
			'restriction' => $this->normalizeRestriction($storage),
		];
	}

	private function normalizeAvatar(Storage $storage): ?array
	{
		$avatarUrl = $this->availableStorageService->resolveSourceAvatarUrl($storage);
		if (!is_string($avatarUrl) || trim($avatarUrl) === '')
		{
			return null;
		}

		return [
			'type' => 'image',
			'url' => $avatarUrl,
		];
	}

	private function normalizeRestriction(Storage $storage): ?array
	{
		if (!$this->tariffRestriction->isRestricted($storage))
		{
			return null;
		}

		return [
			'code' => StorageTariffRestriction::CODE_TARIFF,
			'helperId' => $this->tariffRestriction->getHelperId(),
		];
	}

	private function resolveSection(StorageType $type): string
	{
		return match ($type)
		{
			StorageType::User => self::SECTION_USER,
			StorageType::Common => self::SECTION_COMMON,
			StorageType::Group, StorageType::Project => self::SECTION_GROUP,
			StorageType::Collab => self::SECTION_COLLAB,
		};
	}
}
