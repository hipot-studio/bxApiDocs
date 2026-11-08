<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Storage;

use Bitrix\Disk\Driver;
use Bitrix\Disk\ProxyType;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Disk\Security\FakeSecurityContext;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Storage;
use Bitrix\Disk\User;
use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\DB\PgsqlConnection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\FileTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\Provider\GroupProvider;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\WorkgroupTable;

/**
 * Collects the storages a user may read and tells what kind of storage each one is.
 *
 * Titles and avatars of group storages are resolved here as well: they are loaded together with the group
 * storages and every consumer of the list needs them.
 *
 * Group storages are cached per user, but rights never are: the cache keeps ids, titles and avatars only,
 * and readability is checked on every request.
 */
class AvailableStorageService
{
	private const GROUP_STORAGE_CACHE_VERSION = 'v5';
	private const GROUP_STORAGE_CACHE_TTL = 14400;
	private const GROUP_STORAGE_MANAGED_CACHE_TTL = 31536000;
	private const GROUP_BATCH_SIZE = 300;

	private array $groupTitles = [];
	private array $groupAvatarUrls = [];
	private array $storageTypeCache = [];
	private readonly \Closure $projectStorageChecker;

	public function __construct(?\Closure $projectStorageChecker = null)
	{
		$this->projectStorageChecker = $projectStorageChecker ?? $this->isProjectStorage(...);
	}

	/**
	 * Personal storage first, then common ones, then groups and collabs.
	 *
	 * @return Storage[]
	 */
	public function getReadableStorages(int $userId): array
	{
		$storages = $this->collectUniqueStorages([
			...$this->getReadableUserStorages($userId),
			...$this->getReadableCommonStorages($userId),
			...$this->getReadableGroupStorages($userId),
		]);
		$this->warmUpGroupTypes($storages);

		return $storages;
	}

	public function resolveType(Storage $storage): ?StorageType
	{
		$storageId = (int)$storage->getId();
		if (array_key_exists($storageId, $this->storageTypeCache))
		{
			return $this->storageTypeCache[$storageId];
		}

		$proxyType = $storage->getProxyType();
		$type = match (true)
		{
			$proxyType instanceof ProxyType\User => StorageType::User,
			$proxyType instanceof ProxyType\Common => StorageType::Common,
			$proxyType instanceof ProxyType\Group && $proxyType->isCollab() => StorageType::Collab,
			$proxyType instanceof ProxyType\Group && ($this->projectStorageChecker)($storage) => StorageType::Project,
			$proxyType instanceof ProxyType\Group => StorageType::Group,
			default => null,
		};

		$this->storageTypeCache[$storageId] = $type;

		return $type;
	}

	public function resolveSourceTitle(Storage $storage, array $groupTitles): string
	{
		$proxyType = $storage->getProxyType();
		if ($proxyType instanceof ProxyType\User)
		{
			return (string)$proxyType->getTitleForCurrentUser();
		}

		if ($proxyType instanceof ProxyType\Group)
		{
			return $groupTitles[(int)$storage->getEntityId()] ?? (string)$storage->getName();
		}

		return (string)$storage->getName();
	}

	public function resolveSourceAvatarUrl(Storage $storage): ?string
	{
		if (!$storage->getProxyType() instanceof ProxyType\Group)
		{
			return null;
		}

		return $this->groupAvatarUrls[(int)$storage->getEntityId()] ?? null;
	}

	/**
	 * @param Storage[] $storages
	 */
	public function loadGroupTitlesForStorages(array $storages): array
	{
		$groupIds = $this->collectGroupIds($storages);
		if (empty($groupIds) || !Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		$titles = array_intersect_key($this->groupTitles, $groupIds);
		$loadedGroupIds = array_fill_keys(
			array_keys($this->groupAvatarUrls),
			true,
		);
		$missingGroupIds = array_diff_key($groupIds, $loadedGroupIds);
		if (!empty($missingGroupIds))
		{
			$titles = array_replace(
				$titles,
				$this->loadGroupTitles(array_values($missingGroupIds)),
			);
		}

		return $titles;
	}

	public function isStorageAdministrator(): bool
	{
		if (User::isCurrentUserAdmin())
		{
			return true;
		}

		return Loader::includeModule('socialnetwork')
			&& \CSocNetUser::isCurrentUserModuleAdmin();
	}

	/**
	 * Types of every group storage of the list in one query.
	 *
	 * resolveType() asks the group provider storage by storage and the provider answers from a cache of its
	 * own, so a batch load up front leaves the whole list without a query per storage.
	 *
	 * @param Storage[] $storages
	 */
	private function warmUpGroupTypes(array $storages): void
	{
		$groupIds = $this->collectGroupIds($storages);
		if (empty($groupIds))
		{
			return;
		}

		foreach (array_chunk(array_values($groupIds), self::GROUP_BATCH_SIZE) as $groupIdBatch)
		{
			$this->loadGroupTypes($groupIdBatch);
		}
	}

	protected function loadGroupTypes(array $groupIds): void
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return;
		}

		GroupProvider::getInstance()->loadGroupTypes(...$groupIds);
	}

	/**
	 * @param Storage[] $storages
	 * @return array<int, int>
	 */
	private function collectGroupIds(array $storages): array
	{
		$groupIds = [];
		foreach ($storages as $storage)
		{
			if (!$storage instanceof Storage || !$storage->getProxyType() instanceof ProxyType\Group)
			{
				continue;
			}

			$groupId = (int)$storage->getEntityId();
			if ($groupId > 0)
			{
				$groupIds[$groupId] = $groupId;
			}
		}

		return $groupIds;
	}

	private function collectUniqueStorages(array $storages): array
	{
		$uniqueStorages = [];
		$seenIds = [];

		foreach ($storages as $storage)
		{
			if (!$storage instanceof Storage)
			{
				continue;
			}

			$storageId = (int)$storage->getId();
			if ($storageId <= 0 || isset($seenIds[$storageId]))
			{
				continue;
			}

			$seenIds[$storageId] = true;
			$uniqueStorages[] = $storage;
		}

		return $uniqueStorages;
	}

	protected function getReadableUserStorages(int $userId): array
	{
		$storage = Driver::getInstance()->getStorageByUserId($userId);
		if (!$storage)
		{
			return [];
		}

		$rootObject = $storage->getRootObject();
		$securityContext = $storage->getSecurityContext($userId);
		if (!$rootObject || !$rootObject->canRead($securityContext))
		{
			return [];
		}

		return [$storage];
	}

	protected function getReadableCommonStorages(int $userId): array
	{
		$conditionTree = Query::filter()
			->where('STORAGE.ENTITY_TYPE', ProxyType\Common::class)
		;

		if (defined('SITE_ID') && SITE_ID !== '')
		{
			$conditionTree->where('STORAGE.SITE_ID', SITE_ID);
		}

		return Storage::getReadableList($this->createCommonStorageSecurityContext($userId), [
			'filter' => $conditionTree,
		]);
	}

	protected function getReadableGroupStorages(int $userId): array
	{
		if (!\CBXFeatures::isFeatureEnabled('Workgroups') || !Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		return $this->loadReadableGroupStorages($userId);
	}

	protected function loadReadableGroupStorages(int $userId, ?int $limit = null): array
	{
		$cachedData = $this->loadCachedGroupStorageData($userId);
		if ($cachedData !== null)
		{
			$this->groupTitles = $cachedData['titles'];
			$this->groupAvatarUrls = $cachedData['avatarUrls'];

			$storages = [];
			foreach (array_chunk($cachedData['storageIds'], self::GROUP_BATCH_SIZE) as $storageIds)
			{
				array_push($storages, ...$this->loadStoragesByIds($storageIds, ['ROOT_OBJECT']));
			}
			$storages = $this->orderStoragesByIds($storages, $cachedData['storageIds']);

			return $limit === null ? $storages : array_slice($storages, 0, $limit);
		}

		$storages = $this->collectUniqueStorages(
			$this->queryReadableGroupStorages($userId, $limit),
		);
		$storageIds = [];
		$groupIds = [];
		foreach ($storages as $storage)
		{
			$storageIds[] = (int)$storage->getId();
			$groupId = (int)$storage->getEntityId();
			if ($groupId > 0)
			{
				$groupIds[$groupId] = $groupId;
			}
		}

		$this->groupTitles = empty($groupIds)
			? []
			: $this->loadGroupTitles(array_values($groupIds));
		$this->saveGroupStorageDataToCache($userId, [
			'storageIds' => $storageIds,
			'groupIds' => array_values($groupIds),
			'titles' => $this->groupTitles,
			'avatarUrls' => $this->groupAvatarUrls,
		]);

		return $storages;
	}

	protected function queryReadableGroupStorages(int $userId, ?int $limit = null): array
	{
		$conditionTree = Query::filter()
			->where('STORAGE.ENTITY_TYPE', ProxyType\Group::class)
			->where('UG.USER_ID', $userId)
			->whereIn('UG.ROLE', UserToGroupTable::getRolesMember())
			->where('UG.GROUP.ACTIVE', 'Y')
			->where('UG.GROUP.CLOSED', 'N')
		;

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$parameters = [
			'filter' => $conditionTree,
			'order' => ['STORAGE_ID' => 'ASC'],
			'runtime' => [
				new ReferenceField(
					'UG',
					UserToGroupTable::class,
					[
						'=this.STORAGE.ENTITY_ID' => (
							$connection instanceof PgsqlConnection
								? new SqlExpression($sqlHelper->castToChar('?#'), 'GROUP_ID')
								: 'ref.GROUP_ID'
						),
					],
					['join_type' => 'INNER'],
				),
			],
		];
		if ($limit !== null)
		{
			$parameters['limit'] = $limit;
		}

		return Storage::getReadableList($this->createSecurityContext($userId), $parameters);
	}

	protected function loadCachedGroupStorageData(int $userId): ?array
	{
		$cache = Cache::createInstance();
		if (!$cache->initCache(
			$this->getGroupStorageCacheTtl(),
			$this->getGroupStorageCacheId($userId),
			$this->getGroupStorageCachePath($userId),
		))
		{
			return null;
		}

		$data = $cache->getVars();
		if (
			!is_array($data)
			|| !isset($data['storageIds'], $data['groupIds'], $data['titles'], $data['avatarUrls'])
			|| !is_array($data['storageIds'])
			|| !is_array($data['groupIds'])
			|| !is_array($data['titles'])
			|| !is_array($data['avatarUrls'])
		)
		{
			return null;
		}

		foreach ([...$data['storageIds'], ...$data['groupIds']] as $id)
		{
			if (!is_int($id) || $id <= 0)
			{
				return null;
			}
		}

		foreach ($data['titles'] as $groupId => $title)
		{
			if (!is_int($groupId) || $groupId <= 0 || !is_string($title))
			{
				return null;
			}
		}

		foreach ($data['avatarUrls'] as $groupId => $avatarUrl)
		{
			if (
				!is_int($groupId)
				|| $groupId <= 0
				|| ($avatarUrl !== null && (!is_string($avatarUrl) || trim($avatarUrl) === ''))
			)
			{
				return null;
			}
		}

		return [
			'storageIds' => array_values(array_unique($data['storageIds'])),
			'groupIds' => array_values(array_unique($data['groupIds'])),
			'titles' => $data['titles'],
			'avatarUrls' => $data['avatarUrls'],
		];
	}

	protected function saveGroupStorageDataToCache(int $userId, array $data): void
	{
		$cachePath = $this->getGroupStorageCachePath($userId);
		$cache = Cache::createInstance();
		if (!$cache->startDataCache(
			$this->getGroupStorageCacheTtl(),
			$this->getGroupStorageCacheId($userId),
			$cachePath,
		))
		{
			return;
		}

		$taggedCache = Application::getInstance()->getTaggedCache();
		$taggedCache->startTagCache($cachePath);
		$taggedCache->registerTag("sonet_user2group_U{$userId}");
		$taggedCache->registerTag(Storage::GROUP_STORAGE_CACHE_TAG);
		foreach ($data['groupIds'] as $groupId)
		{
			$taggedCache->registerTag("sonet_group_{$groupId}");
			$taggedCache->registerTag("sonet_features_G_{$groupId}");
		}
		$taggedCache->endTagCache();

		$cache->endDataCache($data);
	}

	private function getGroupStorageCacheTtl(): int
	{
		return defined('BX_COMP_MANAGED_CACHE')
			? self::GROUP_STORAGE_MANAGED_CACHE_TTL
			: self::GROUP_STORAGE_CACHE_TTL;
	}

	private function getGroupStorageCacheId(int $userId): string
	{
		$siteId = defined('SITE_ID') ? (string)SITE_ID : '';

		return self::GROUP_STORAGE_CACHE_VERSION . '_file_picker_group_storages_'
			. md5($siteId)
			. "_{$userId}";
	}

	private function getGroupStorageCachePath(int $userId): string
	{
		return '/disk/file_picker/group_sources/'
			. str_pad((string)($userId % 100), 2, '0', STR_PAD_LEFT)
			. "/{$userId}";
	}

	private function orderStoragesByIds(array $storages, array $storageIds): array
	{
		$storagesById = [];
		foreach ($storages as $storage)
		{
			if ($storage instanceof Storage)
			{
				$storagesById[(int)$storage->getId()] = $storage;
			}
		}

		$orderedStorages = [];
		foreach ($storageIds as $storageId)
		{
			if (isset($storagesById[$storageId]))
			{
				$orderedStorages[] = $storagesById[$storageId];
			}
		}

		return $orderedStorages;
	}

	protected function loadGroupTitles(array $groupIds): array
	{
		$titles = [];
		foreach (array_chunk($groupIds, self::GROUP_BATCH_SIZE) as $groupIdBatch)
		{
			$rows = [];
			$imageIds = [];
			foreach ($this->loadGroupTitleRows($groupIdBatch) as $row)
			{
				$rows[] = $row;
				$imageIds[] = (int)($row['IMAGE_ID'] ?? 0);
			}
			$avatarUrls = $this->resolveGroupAvatarUrls($imageIds);

			foreach ($rows as $row)
			{
				$groupId = (int)$row['ID'];
				$title = trim((string)$row['NAME']);
				if ($title !== '')
				{
					$titles[$groupId] = $title;
				}
				$imageId = (int)($row['IMAGE_ID'] ?? 0);
				$this->groupAvatarUrls[$groupId] = $avatarUrls[$imageId] ?? null;
			}
		}

		return $titles;
	}

	protected function loadGroupTitleRows(array $groupIds): iterable
	{
		return WorkgroupTable::getList([
			'select' => ['ID', 'NAME', 'IMAGE_ID'],
			'filter' => ['@ID' => $groupIds],
		]);
	}

	protected function resolveGroupAvatarUrls(array $imageIds): array
	{
		$imageIds = array_values(array_unique(array_filter(
			array_map(static fn(mixed $imageId): int => (int)$imageId, $imageIds),
			static fn(int $imageId): bool => $imageId > 0,
		)));
		if ($imageIds === [])
		{
			return [];
		}

		$avatarUrls = [];
		foreach ($this->loadGroupAvatarFileRows($imageIds) as $file)
		{
			$file['SRC'] = \CFile::GetFileSRC($file);
			$resizedFile = \CFile::ResizeImageGet(
				$file,
				['width' => 48, 'height' => 48],
				BX_RESIZE_IMAGE_EXACT,
				false,
				false,
				Context::getCurrent()->getRequest()->isAjaxRequest(),
			);
			$avatarUrl = is_array($resizedFile) ? ($resizedFile['src'] ?? null) : null;
			if (is_string($avatarUrl) && trim($avatarUrl) !== '')
			{
				$avatarUrls[(int)$file['ID']] = $avatarUrl;
			}
		}

		return $avatarUrls;
	}

	protected function loadGroupAvatarFileRows(array $imageIds): iterable
	{
		return FileTable::query()
			->setSelect(['ID', 'WIDTH', 'HEIGHT', 'FILE_SIZE', 'SUBDIR', 'FILE_NAME', 'HANDLER_ID'])
			->whereIn('ID', $imageIds)
			->exec()
		;
	}

	protected function loadStoragesByIds(array $storageIds, array $with = []): array
	{
		return Storage::loadBatchById($storageIds, $with);
	}

	protected function createSecurityContext(int $userId): SecurityContext
	{
		return new DiskSecurityContext($userId);
	}

	protected function createCommonStorageSecurityContext(int $userId): SecurityContext
	{
		if ($this->isStorageAdministrator())
		{
			return new FakeSecurityContext($userId);
		}

		return $this->createSecurityContext($userId);
	}

	private function isProjectStorage(Storage $storage): bool
	{
		$groupId = (int)$storage->getEntityId();
		if ($groupId <= 0 || !Loader::includeModule('socialnetwork'))
		{
			return false;
		}

		// scrum groups count as projects: they are stored with PROJECT = Y
		return in_array(
			GroupProvider::getInstance()->getGroupType($groupId),
			[Type::Project, Type::Scrum],
			true,
		);
	}
}
