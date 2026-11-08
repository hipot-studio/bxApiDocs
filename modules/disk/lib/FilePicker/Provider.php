<?php

declare(strict_types=1);

namespace Bitrix\Disk\FilePicker;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\FolderTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\ProxyType;
use Bitrix\Disk\RecentlyUsedManager;
use Bitrix\Disk\Search\StorageFileFinder;
use Bitrix\Disk\Search\StorageFileFinderOptions;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Disk\Security\FakeSecurityContext;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Storage;
use Bitrix\Disk\User;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\FileTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\Provider\GroupProvider;
use Bitrix\Socialnetwork\WorkgroupTable;

class Provider
{
	public const INITIAL_STAGE_RECENT = 'recent';
	public const INITIAL_STAGE_FOLDER = 'folder';
	public const INITIAL_STAGE_SOURCES = 'sources';

	public const ORDER_FIELD_NAME = 'name';
	public const ORDER_FIELD_CREATE_TIME = 'createTime';
	public const ORDER_FIELD_UPDATE_TIME = 'updateTime';

	public const ORDER_DIRECTION_ASC = 'asc';
	public const ORDER_DIRECTION_DESC = 'desc';

	public const DEFAULT_PAGE = 1;
	public const DEFAULT_PAGE_SIZE = 50;
	public const MAX_PAGE_SIZE = 100;
	public const DEFAULT_ORDER = [
		'field' => self::ORDER_FIELD_NAME,
		'direction' => self::ORDER_DIRECTION_ASC,
	];
	public const DEFAULT_INITIAL_STAGE = [
		'type' => self::INITIAL_STAGE_RECENT,
		'storageId' => null,
		'folderId' => null,
	];
	private const RUNTIME_FILTER_BATCH_SIZE = 100;
	private const MAX_PAGE_OFFSET = 1000;
	private const MAX_RUNTIME_FILTER_SCAN = 3000;
	private const WARM_UP_BATCH_SIZE = 300;
	private const GROUP_STORAGE_CACHE_VERSION = 'v5';
	private const SOURCE_CATALOGUE_CACHE_VERSION = 'v1';
	private const GROUP_STORAGE_CACHE_TTL = 14400;
	private const GROUP_STORAGE_MANAGED_CACHE_TTL = 31536000;
	private const SUPPORTED_STORAGE_PROXY_TYPES = [
		ProxyType\User::class,
		ProxyType\Common::class,
		ProxyType\Group::class,
		ItemNormalizer::IM_STORAGE_PROXY_TYPE,
	];
	/**
	 * Storages the administrator bypass never reaches: membership in a chat is the only thing
	 * that opens its storage, and a portal or socialnetwork administrator is not a member.
	 */
	private const STRICT_RIGHTS_STORAGE_PROXY_TYPES = [
		ItemNormalizer::IM_STORAGE_PROXY_TYPE,
	];

	private array $groupSourceTitles = [];
	private array $groupSourceAvatarUrls = [];

	public function __construct(
		private readonly Context $context,
		private readonly ItemNormalizer $itemNormalizer = new ItemNormalizer(),
		private readonly FileTypeClassifier $fileTypeClassifier = new FileTypeClassifier(),
		private readonly ViewerAttributesFactory $viewerAttributesFactory = new ViewerAttributesFactory(),
	)
	{
	}

	public function getInitialStage(
		array $initialStage,
		Filter $filter,
		int $pageSize = self::DEFAULT_PAGE_SIZE,
		bool $includeTotal = false,
		int $page = self::DEFAULT_PAGE,
	): Result
	{
		if ($includeTotal)
		{
			return (new Result())->addError(Error::create(Error::TOTAL_UNAVAILABLE));
		}

		if (!$this->isValidInitialStage($initialStage))
		{
			return (new Result())->addError(Error::create(Error::INVALID_CONTEXT));
		}

		$type = $initialStage['type'];
		$normalizedPageSize = $this->normalizePageSize($pageSize);
		if (
			$type === self::INITIAL_STAGE_SOURCES
				? !$this->isSourcePageValid($page, $normalizedPageSize)
				: !$this->isPageWithinOffsetLimit($page, $normalizedPageSize)
		)
		{
			return (new Result())->addError(Error::create(Error::INVALID_CONTEXT));
		}
		if ($type !== self::INITIAL_STAGE_SOURCES && $page !== self::DEFAULT_PAGE)
		{
			return (new Result())->addError(Error::create(Error::INVALID_CONTEXT));
		}

		return match ($type)
		{
			self::INITIAL_STAGE_RECENT => $this->getRecent($filter, $pageSize),
			self::INITIAL_STAGE_FOLDER => $this->getFolderInitialStage(
				$initialStage['storageId'],
				$initialStage['folderId'],
				$filter,
				self::DEFAULT_ORDER,
				$pageSize,
			),
			self::INITIAL_STAGE_SOURCES => $this->getSources($page, $pageSize),
			default => (new Result())->addError(Error::create(Error::INVALID_CONTEXT)),
		};
	}

	private function isValidInitialStage(array $initialStage): bool
	{
		$fields = ['type' => true, 'storageId' => true, 'folderId' => true];
		if (
			!array_key_exists('type', $initialStage)
			|| array_diff_key($initialStage, $fields) !== []
		)
		{
			return false;
		}

		if ($initialStage['type'] === self::INITIAL_STAGE_FOLDER)
		{
			return array_key_exists('storageId', $initialStage)
				&& array_key_exists('folderId', $initialStage)
				&& is_int($initialStage['storageId'])
				&& $initialStage['storageId'] > 0
				&& is_int($initialStage['folderId'])
				&& $initialStage['folderId'] > 0;
		}

		return in_array($initialStage['type'], [self::INITIAL_STAGE_RECENT, self::INITIAL_STAGE_SOURCES], true)
			&& ($initialStage['storageId'] ?? null) === null
			&& ($initialStage['folderId'] ?? null) === null;
	}

	private function getFolderInitialStage(
		int $storageId,
		int $folderId,
		Filter $filter,
		array $order,
		int $pageSize,
	): Result
	{
		$result = $this->listChildren($storageId, $folderId, $filter, $order, self::DEFAULT_PAGE, $pageSize, false);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$data = $result->getData();
		$data = [
			'stage' => ['type' => self::INITIAL_STAGE_FOLDER, 'storageId' => $storageId, 'folderId' => $folderId],
			'items' => $data['items'],
			'sources' => [],
			'pagination' => $data['pagination'],
			'context' => $data['context'],
			'emptyReason' => $data['emptyReason'],
			'sourceRevision' => null,
		];

		return (new Result())->setData($data);
	}

	public function getRecent(Filter $filter, int $pageSize = self::DEFAULT_PAGE_SIZE): Result
	{
		$result = new Result();
		$pageSize = $this->normalizePageSize($pageSize);
		$items = [];
		$seenIds = [];

		$recentRecords = $this->loadRecentRecords($this->context->getUserId());
		$this->warmUpObjectMetadata(array_column($recentRecords, 'file'));

		foreach ($recentRecords as $record)
		{
			$file = $record['file'] ?? null;
			$recentTime = $record['recentTime'] ?? null;
			if (!$file instanceof File || !$recentTime instanceof DateTime)
			{
				continue;
			}

			$objectId = (int)$file->getId();
			if ($objectId <= 0 || isset($seenIds[$objectId]))
			{
				continue;
			}

			$seenIds[$objectId] = true;
			if (!$filter->shouldReturnObject($file, $this->fileTypeClassifier))
			{
				continue;
			}

			$item = $this->itemNormalizer->normalize($file);
			if ($item === null)
			{
				continue;
			}

			$item['recentTime'] = $recentTime->format('c');
			$items[] = $item;
			if (\count($items) >= $pageSize)
			{
				break;
			}
		}

		$result->setData([
			'stage' => ['type' => self::INITIAL_STAGE_RECENT, 'storageId' => null, 'folderId' => null],
			'items' => $items,
			'sources' => [],
			'pagination' => ['hasMore' => false, 'total' => null],
			'context' => $this->getEmptyNavigationContext(),
			'emptyReason' => empty($items) ? 'empty' : null,
			'sourceRevision' => null,
		]);

		return $result;
	}

	public function getSources(
		int $page = self::DEFAULT_PAGE,
		int $pageSize = self::DEFAULT_PAGE_SIZE,
	): Result
	{
		$result = new Result();
		$pageSize = $this->normalizePageSize($pageSize);
		if (!$this->isSourcePageValid($page, $pageSize))
		{
			return $result->addError(Error::create(Error::INVALID_CONTEXT));
		}

		$catalogue = $this->loadCachedSourceCatalogue() ?? $this->buildSourceCatalogue();
		$offset = ($page - 1) * $pageSize;
		$sources = \array_slice($catalogue['sources'], $offset, $pageSize);
		$hasMore = $offset + \count($sources) < \count($catalogue['sources']);

		$result->setData([
			'stage' => ['type' => self::INITIAL_STAGE_SOURCES, 'storageId' => null, 'folderId' => null],
			'items' => [],
			'sources' => $sources,
			'pagination' => ['hasMore' => $hasMore, 'total' => null],
			'context' => $this->getEmptyNavigationContext(),
			'emptyReason' => $page === self::DEFAULT_PAGE && empty($sources) ? 'empty' : null,
			'sourceRevision' => $catalogue['revision'],
		]);

		return $result;
	}

	private function buildSourceCatalogue(): array
	{
		$storages = $this->collectUniqueStorages($this->loadSourceStorages());
		$this->warmUpStorageMetadata($storages);
		$groupTitles = $this->loadGroupTitlesForStorages($storages);
		$sources = [];
		$groupIds = [];

		foreach ($storages as $storage)
		{
			if ($storage->getProxyType() instanceof ProxyType\Group)
			{
				$groupId = (int)$storage->getEntityId();
				if ($groupId > 0)
				{
					$groupIds[$groupId] = $groupId;
				}
			}

			$source = $this->itemNormalizer->normalizeSourceDescriptor(
				$storage,
				$this->resolveSourceTitle($storage, $groupTitles),
				$this->resolveSourceAvatarUrl($storage),
			);
			if ($source !== null)
			{
				$sources[] = $source;
			}
		}

		$catalogue = [
			'sources' => $sources,
			'revision' => hash('sha256', serialize($sources)),
		];
		$this->saveSourceCatalogueToCache($catalogue, array_values($groupIds));

		return $catalogue;
	}

	protected function loadCachedSourceCatalogue(): ?array
	{
		$cache = Cache::createInstance();
		if (!$cache->initCache(
			$this->getGroupStorageCacheTtl(),
			$this->getSourceCatalogueCacheId(),
			$this->getSourceCatalogueCachePath(),
		))
		{
			return null;
		}

		$data = $cache->getVars();
		if (
			!\is_array($data)
			|| !isset($data['sources'], $data['revision'])
			|| !\is_array($data['sources'])
			|| !\is_string($data['revision'])
			|| preg_match('/^[a-f0-9]{64}$/D', $data['revision']) !== 1
		)
		{
			return null;
		}

		return [
			'sources' => array_values($data['sources']),
			'revision' => $data['revision'],
		];
	}

	protected function saveSourceCatalogueToCache(array $data, array $groupIds): void
	{
		$cachePath = $this->getSourceCatalogueCachePath();
		$cache = Cache::createInstance();
		if (!$cache->startDataCache(
			$this->getGroupStorageCacheTtl(),
			$this->getSourceCatalogueCacheId(),
			$cachePath,
		))
		{
			return;
		}

		$taggedCache = Application::getInstance()->getTaggedCache();
		$taggedCache->startTagCache($cachePath);
		$taggedCache->registerTag('sonet_user2group_U' . $this->context->getUserId());
		$taggedCache->registerTag(Storage::GROUP_STORAGE_CACHE_TAG);
		$taggedCache->registerTag('disk_common_storage');
		foreach ($groupIds as $groupId)
		{
			$taggedCache->registerTag("sonet_group_{$groupId}");
			$taggedCache->registerTag("sonet_features_G_{$groupId}");
		}
		$taggedCache->endTagCache();

		$cache->endDataCache($data);
	}

	private function getSourceCatalogueCacheId(): string
	{
		$siteId = defined('SITE_ID') ? (string)SITE_ID : '';
		$languageId = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : '';

		return self::SOURCE_CATALOGUE_CACHE_VERSION . '_file_picker_sources_'
			. md5($siteId . ':' . $languageId)
			. '_' . $this->context->getUserId();
	}

	private function getSourceCatalogueCachePath(): string
	{
		$userId = $this->context->getUserId();

		return '/disk/file_picker/source_catalogue/'
			. str_pad((string)($userId % 100), 2, '0', STR_PAD_LEFT)
			. "/{$userId}";
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

	private function getReadableSourceStorages(): array
	{
		$storages = [
			...$this->getReadableUserStorages(),
			...$this->getReadableCommonStorages(),
		];

		return [...$storages, ...$this->getReadableGroupStorages()];
	}

	private function getReadableUserStorages(): array
	{
		$storage = Driver::getInstance()->getStorageByUserId($this->context->getUserId());
		if (!$storage)
		{
			return [];
		}

		$rootObject = $storage->getRootObject();
		$securityContext = $storage->getSecurityContext($this->context->getUserId());
		if (!$rootObject || !$rootObject->canRead($securityContext))
		{
			return [];
		}

		return [$storage];
	}

	private function getReadableCommonStorages(): array
	{
		$conditionTree = Query::filter()
			->where('STORAGE.ENTITY_TYPE', ProxyType\Common::class)
		;

		if (defined('SITE_ID') && SITE_ID !== '')
		{
			$conditionTree->where('STORAGE.SITE_ID', SITE_ID);
		}

		return Storage::getReadableList($this->createCommonStorageSecurityContext(), [
			'filter' => $conditionTree,
		]);
	}

	private function getReadableGroupStorages(): array
	{
		if (!\CBXFeatures::isFeatureEnabled('Workgroups') || !Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		return $this->loadReadableGroupStorages();
	}

	protected function loadReadableGroupStorages(?int $limit = null): array
	{
		$userId = $this->context->getUserId();
		$cachedData = $this->loadCachedGroupStorageData($userId);
		if ($cachedData !== null)
		{
			$this->groupSourceTitles = $cachedData['titles'];
			$this->groupSourceAvatarUrls = $cachedData['avatarUrls'];

			$storages = [];
			foreach (\array_chunk($cachedData['storageIds'], self::WARM_UP_BATCH_SIZE) as $storageIds)
			{
				\array_push($storages, ...$this->loadStoragesByIds($storageIds, ['ROOT_OBJECT']));
			}
			$storages = $this->orderStoragesByIds(
				$storages,
				$cachedData['storageIds'],
			);

			return $limit === null ? $storages : \array_slice($storages, 0, $limit);
		}

		$storages = $this->collectUniqueStorages(
			$this->queryReadableGroupStorages($limit),
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

		$this->groupSourceTitles = empty($groupIds)
			? []
			: $this->loadGroupTitles(array_values($groupIds));
		$this->saveGroupStorageDataToCache($userId, [
			'storageIds' => $storageIds,
			'groupIds' => array_values($groupIds),
			'titles' => $this->groupSourceTitles,
			'avatarUrls' => $this->groupSourceAvatarUrls,
		]);

		return $storages;
	}

	protected function queryReadableGroupStorages(?int $limit = null): array
	{
		$userId = $this->context->getUserId();
		$conditionTree = Query::filter()
			->where('STORAGE.ENTITY_TYPE', ProxyType\Group::class)
			->where('UG.USER_ID', $userId)
			->whereIn('UG.ROLE', \Bitrix\Socialnetwork\UserToGroupTable::getRolesMember())
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
					'Bitrix\Socialnetwork\UserToGroupTable',
					[
						'=this.STORAGE.ENTITY_ID' => (
							$connection instanceof \Bitrix\Main\DB\PgsqlConnection
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

		return Storage::getReadableList($this->createSecurityContext(), $parameters);
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
			!\is_array($data)
			|| !isset($data['storageIds'], $data['groupIds'], $data['titles'], $data['avatarUrls'])
			|| !\is_array($data['storageIds'])
			|| !\is_array($data['groupIds'])
			|| !\is_array($data['titles'])
			|| !\is_array($data['avatarUrls'])
		)
		{
			return null;
		}

		foreach ([...$data['storageIds'], ...$data['groupIds']] as $id)
		{
			if (!\is_int($id) || $id <= 0)
			{
				return null;
			}
		}

		foreach ($data['titles'] as $groupId => $title)
		{
			if (!\is_int($groupId) || $groupId <= 0 || !\is_string($title))
			{
				return null;
			}
		}

		foreach ($data['avatarUrls'] as $groupId => $avatarUrl)
		{
			if (
				!\is_int($groupId)
				|| $groupId <= 0
				|| ($avatarUrl !== null && (!\is_string($avatarUrl) || trim($avatarUrl) === ''))
			)
			{
				return null;
			}
		}

		return [
			'storageIds' => \array_values(\array_unique($data['storageIds'])),
			'groupIds' => \array_values(\array_unique($data['groupIds'])),
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

	private function resolveSourceTitle(Storage $storage, array $groupTitles): string
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

	private function resolveSourceAvatarUrl(Storage $storage): ?string
	{
		if (!$storage->getProxyType() instanceof ProxyType\Group)
		{
			return null;
		}

		return $this->groupSourceAvatarUrls[(int)$storage->getEntityId()] ?? null;
	}

	private function loadGroupTitlesForStorages(array $storages): array
	{
		$groupIds = [];
		foreach ($storages as $storage)
		{
			if (!$storage instanceof Storage || $storage->getEntityType() !== ProxyType\Group::class)
			{
				continue;
			}

			$groupId = (int)$storage->getEntityId();
			if ($groupId > 0)
			{
				$groupIds[$groupId] = $groupId;
			}
		}

		if (empty($groupIds) || !Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		$titles = \array_intersect_key($this->groupSourceTitles, $groupIds);
		$loadedGroupIds = \array_fill_keys(
			\array_keys($this->groupSourceAvatarUrls),
			true,
		);
		$missingGroupIds = \array_diff_key($groupIds, $loadedGroupIds);
		if (!empty($missingGroupIds))
		{
			$titles = \array_replace(
				$titles,
				$this->loadGroupTitles(array_values($missingGroupIds)),
			);
		}

		return $titles;
	}

	protected function createSecurityContext(): SecurityContext
	{
		return new DiskSecurityContext($this->context->getUserId());
	}

	protected function createCommonStorageSecurityContext(): SecurityContext
	{
		if ($this->isStorageAdministrator())
		{
			return new FakeSecurityContext($this->context->getUserId());
		}

		return $this->createSecurityContext();
	}

	protected function createResolveSelectionSecurityContext(): SecurityContext
	{
		if ($this->isStorageAdministrator())
		{
			return new FakeSecurityContext($this->context->getUserId());
		}

		return $this->createSecurityContext();
	}

	protected function isStorageAdministrator(): bool
	{
		if (User::isCurrentUserAdmin())
		{
			return true;
		}

		return Loader::includeModule('socialnetwork')
			&& \CSocNetUser::isCurrentUserModuleAdmin();
	}

	/**
	 * Preloads everything normalization asks for object by object: storages of the page and types of their groups.
	 */
	private function warmUpObjectMetadata(array $objects): void
	{
		$this->itemNormalizer->warmUpItems($objects);

		$storageIds = [];
		foreach ($objects as $object)
		{
			if (!$object instanceof BaseObject)
			{
				continue;
			}

			$storageId = (int)$object->getStorageId();
			if ($storageId > 0)
			{
				$storageIds[$storageId] = $storageId;
			}
		}

		if (empty($storageIds))
		{
			return;
		}

		$storages = [];
		foreach (array_chunk(array_values($storageIds), self::WARM_UP_BATCH_SIZE) as $chunk)
		{
			array_push($storages, ...$this->loadStoragesByIds($chunk));
		}

		$this->warmUpStorageMetadata($storages);
	}

	private function warmUpStorageMetadata(array $storages): void
	{
		$groupIds = [];
		foreach ($storages as $storage)
		{
			if (!$storage instanceof Storage || $storage->getEntityType() !== ProxyType\Group::class)
			{
				continue;
			}

			$groupId = (int)$storage->getEntityId();
			if ($groupId > 0)
			{
				$groupIds[$groupId] = $groupId;
			}
		}

		if (empty($groupIds) || !Loader::includeModule('socialnetwork'))
		{
			return;
		}

		foreach (array_chunk(array_values($groupIds), self::WARM_UP_BATCH_SIZE) as $chunk)
		{
			GroupProvider::getInstance()->loadGroupTypes(...$chunk);
		}
	}

	public function listChildren(
		int $storageId,
		int $folderId,
		Filter $filter,
		array $order = self::DEFAULT_ORDER,
		int $page = self::DEFAULT_PAGE,
		int $pageSize = self::DEFAULT_PAGE_SIZE,
		bool $includeTotal = false,
	): Result
	{
		$result = new Result();
		$pageSize = $this->normalizePageSize($pageSize);
		if (
			$storageId <= 0
			|| $folderId <= 0
			|| !$this->isPageWithinOffsetLimit($page, $pageSize)
		)
		{
			return $result->addError(Error::create(Error::INVALID_CONTEXT));
		}

		$orderResult = $this->normalizeOrder($order);
		if (!$orderResult->isSuccess())
		{
			return $result->addErrors($orderResult->getErrors());
		}

		if ($includeTotal && $filter->needsRuntimeFileTypeFiltering())
		{
			return $result->addError(Error::create(Error::TOTAL_UNAVAILABLE));
		}

		$folderContext = $this->loadReadableFolderContext($storageId, $folderId);
		if (!is_array($folderContext) || \count($folderContext) !== 3)
		{
			return $result->addError(Error::create(Error::NOT_FOUND));
		}

		[$storage, $folder, $securityContext] = $folderContext;
		if (
			!$storage instanceof Storage
			|| !$folder instanceof Folder
			|| !$securityContext instanceof SecurityContext
		)
		{
			return $result->addError(Error::create(Error::NOT_FOUND));
		}

		$offset = ($page - 1) * $pageSize;
		$pageData = $this->loadChildrenPage(
			$storage,
			$folder,
			$filter,
			$orderResult->getData()['order'],
			$offset,
			$pageSize + 1,
		);
		$objects = $pageData['objects'];
		$hasMore = \count($objects) > $pageSize;
		$objects = \array_slice($objects, 0, $pageSize);
		$total = $includeTotal ? $this->countChildren($storage, $folder, $filter) : null;

		// The storages of a folder page are known already, only the items themselves need warming up.
		$this->itemNormalizer->warmUpItems($objects);

		$items = [];
		foreach ($objects as $object)
		{
			$item = $this->itemNormalizer->normalize($object);
			if ($item !== null)
			{
				$items[] = $item;
			}
		}

		$parents = $this->loadParentFolders($folder, $securityContext);
		$navigationContext = $this->itemNormalizer->normalizeNavigationContext(
			$storage,
			$folder,
			$parents,
			$this->context->getUserId(),
		);
		if ($navigationContext === null)
		{
			return $result->addError(Error::create(Error::NOT_FOUND));
		}

		$result->setData([
			'items' => $items,
			'pagination' => ['hasMore' => $hasMore, 'total' => $total],
			'context' => $navigationContext,
			'emptyReason' => empty($items) ? 'empty' : null,
		]);

		return $result;
	}

	public function search(
		string $query,
		Filter $filter,
		int $page = self::DEFAULT_PAGE,
		int $pageSize = self::DEFAULT_PAGE_SIZE,
		?int $storageId = null,
		bool $includeTotal = false,
	): Result
	{
		$result = new Result();
		$query = trim($query);
		$queryLength = mb_strlen($query);
		$pageSize = $this->normalizePageSize($pageSize);
		if (
			$queryLength < 3
			|| $queryLength > 255
			|| !$this->isPageWithinOffsetLimit($page, $pageSize)
			|| ($storageId !== null && $storageId <= 0)
		)
		{
			return $result->addError(Error::create(Error::INVALID_QUERY));
		}
		if ($includeTotal)
		{
			return $result->addError(Error::create(Error::TOTAL_UNAVAILABLE));
		}

		$offset = ($page - 1) * $pageSize;
		$pageData = $this->searchPage($query, $filter, $storageId, $offset, $pageSize + 1);
		$objects = $pageData['objects'];
		$hasMore = \count($objects) > $pageSize;
		$objects = \array_slice($objects, 0, $pageSize);
		$this->warmUpObjectMetadata($objects);

		$items = [];
		foreach ($objects as $object)
		{
			$item = $this->itemNormalizer->normalize($object);
			if ($item !== null)
			{
				$items[] = $item;
			}
		}

		$result->setData([
			'items' => $items,
			'pagination' => ['hasMore' => $hasMore, 'total' => null],
			'emptyReason' => empty($items) ? 'empty' : null,
		]);

		return $result;
	}

	public function resolveSelection(
		array $objectIds,
		string $selectionMode = SignedConfig::SELECTION_MODE_SINGLE,
		?int $maxItems = null,
		array $allowedFileTypes = [],
	): Result
	{
		$result = new Result();
		$ids = $this->uniquePositiveIds($objectIds);
		if (empty($ids))
		{
			$result->setData([
				'items' => [],
				'rejectedItems' => [],
				'partial' => false,
			]);

			return $result;
		}

		if (\count($ids) > self::MAX_PAGE_SIZE)
		{
			return $result->addError(Error::create(Error::TOO_MANY_ITEMS));
		}

		$signedConfig = $this->context->getSignedConfig();
		if ($signedConfig)
		{
			$selectionMode = $signedConfig->getSelectionMode();
			$maxItems = $signedConfig->getMaxItemsLimit();
			$allowedFileTypes = $signedConfig->getAllowedFileTypes();
		}
		else
		{
			if (!\in_array($selectionMode, [
				SignedConfig::SELECTION_MODE_SINGLE,
				SignedConfig::SELECTION_MODE_MULTIPLE,
			], true))
			{
				return $result->addError(Error::create(Error::INVALID_CONTEXT));
			}

			if ($maxItems !== null && $maxItems <= 0)
			{
				return $result->addError(Error::create(Error::INVALID_CONTEXT));
			}

			$maxItems = min($maxItems ?? self::MAX_PAGE_SIZE, self::MAX_PAGE_SIZE);
			if ($selectionMode === SignedConfig::SELECTION_MODE_SINGLE)
			{
				$maxItems = 1;
			}
		}

		$filterResult = Filter::create(Filter::OBJECT_TYPE_FILES, $allowedFileTypes, []);
		if (!$filterResult->isSuccess())
		{
			return $result->addErrors($filterResult->getErrors());
		}
		$constraintFilter = empty($allowedFileTypes) ? null : $filterResult->getData()['filter'];

		if (\count($ids) > $maxItems)
		{
			return $result->addError(Error::create(Error::TOO_MANY_ITEMS));
		}

		$loadedObjects = [];
		foreach ($this->loadReadableObjectsByIds($ids) as $object)
		{
			if (!$object instanceof BaseObject)
			{
				continue;
			}

			$loadedObjects[(int)$object->getId()] = $object;
		}

		$this->warmUpObjectMetadata($loadedObjects);

		$acceptedFiles = [];
		$rejectionReasons = [];
		foreach ($ids as $id)
		{
			$object = $loadedObjects[$id] ?? null;
			if (!$object || $object->isDeleted())
			{
				$rejectionReasons[$id] = Error::NOT_FOUND;

				continue;
			}

			if (!$object instanceof File)
			{
				$rejectionReasons[$id] = Error::NOT_SELECTABLE;

				continue;
			}

			if ($constraintFilter && !$constraintFilter->shouldReturnObject($object, $this->fileTypeClassifier))
			{
				$rejectionReasons[$id] = Error::NOT_SELECTABLE;

				continue;
			}

			$acceptedFiles[$id] = $object;
		}

		$parentIds = [];
		foreach ($acceptedFiles as $file)
		{
			$parentId = (int)$file->getParentId();
			if ($parentId > 0)
			{
				$parentIds[$parentId] = true;
			}
		}

		$parentFolders = [];
		foreach (array_chunk(array_keys($parentIds), self::WARM_UP_BATCH_SIZE) as $parentIdBatch)
		{
			foreach ($this->loadSelectionParentFolderBatch($parentIdBatch) as $folder)
			{
				if (!$folder instanceof Folder)
				{
					continue;
				}

				$parentId = (int)$folder->getId();
				if ($parentId > 0 && isset($parentIds[$parentId]))
				{
					$parentFolders[$parentId] = $folder;
				}
			}
		}

		$items = [];
		$rejectedItems = [];
		foreach ($ids as $id)
		{
			if (isset($rejectionReasons[$id]))
			{
				$rejectedItems[] = ['objectId' => $id, 'reason' => $rejectionReasons[$id]];

				continue;
			}

			$file = $acceptedFiles[$id];
			$parentFolder = $parentFolders[(int)$file->getParentId()] ?? null;
			$item = $parentFolder instanceof Folder
				? $this->itemNormalizer->normalizeSelection($file, $parentFolder)
				: null
			;
			if ($item === null)
			{
				$rejectedItems[] = ['objectId' => $id, 'reason' => Error::NOT_FOUND];

				continue;
			}

			$items[] = $item;
		}

		$result->setData([
			'items' => $items,
			'rejectedItems' => $rejectedItems,
			'partial' => !empty($rejectedItems),
		]);

		return $result;
	}

	/**
	 * Viewer attributes of the requested files, in the order they were asked for. A file the user may not
	 * read, a folder, a deleted object and a file the picker must not open are all left out with no trace:
	 * the answer never tells whether an object exists.
	 */
	public function getViewerAttributes(array $objectIds): Result
	{
		$result = new Result();
		$ids = $this->uniquePositiveIds($objectIds);
		if (empty($ids))
		{
			return $result->setData(['items' => []]);
		}

		// The limit counts the items actually asked about, as the selection does: a repeated id is one item.
		if (\count($ids) > self::MAX_PAGE_SIZE)
		{
			return $result->addError(Error::create(Error::TOO_MANY_ITEMS));
		}

		$loadedObjects = [];
		foreach ($this->loadReadableObjectsByIds($ids) as $object)
		{
			if (!$object instanceof BaseObject)
			{
				continue;
			}

			$loadedObjects[(int)$object->getId()] = $object;
		}

		$files = [];
		foreach ($ids as $id)
		{
			$object = $loadedObjects[$id] ?? null;
			if ($object instanceof File && !$object->isDeleted())
			{
				$files[$id] = $object;
			}
		}

		$securityContexts = $this->preloadRightsForViewerAttributes($files);
		$this->viewerAttributesFactory->preloadFileData($files);

		$items = [];
		foreach ($files as $id => $file)
		{
			$attributes = $this->viewerAttributesFactory->build($file, $securityContexts[$id]);
			if ($attributes === null)
			{
				continue;
			}

			$items[] = ['objectId' => $id, 'attributes' => $attributes];
		}

		return $result->setData(['items' => $items]);
	}

	/**
	 * One security context per kind of rights for the whole batch, with the rights and the download access
	 * of its files read in one go. A context of its own per file shares none of its caches, so every file
	 * would pay for its own rights, object and option queries.
	 *
	 * The context is warmed by object ids and not by objects on purpose: that is the path where the real
	 * objects behind the ids are resolved for the whole batch at once as well.
	 *
	 * @param array<int, File> $filesByObjectId
	 * @return array<int, SecurityContext>
	 */
	private function preloadRightsForViewerAttributes(array $filesByObjectId): array
	{
		$groups = $this->groupFilesByRightsKind($filesByObjectId);

		$securityContextByObjectId = [];
		foreach ($groups as $kind => $files)
		{
			if (empty($files))
			{
				continue;
			}

			$securityContext = $kind === 'strict'
				? $this->createSecurityContext()
				: $this->createResolveSelectionSecurityContext()
			;

			if ($securityContext instanceof DiskSecurityContext)
			{
				$securityContext->preloadOperationsForObjectIds($this->collectRightsObjectIds($files));
			}

			foreach (array_keys($files) as $objectId)
			{
				$securityContextByObjectId[$objectId] = $securityContext;
			}
		}

		return $securityContextByObjectId;
	}

	/**
	 * The two kinds of rights differ by the administrator bypass alone. Without it the whole batch is one
	 * group: a second context would answer exactly the same and share none of the caches of the first, so
	 * splitting the batch would only pay for the rights and the download access twice.
	 *
	 * @param array<int, File> $filesByObjectId
	 * @return array<string, array<int, File>>
	 */
	private function groupFilesByRightsKind(array $filesByObjectId): array
	{
		if (!$this->isStorageAdministrator())
		{
			return ['strict' => $filesByObjectId];
		}

		$groups = ['strict' => [], 'common' => []];
		foreach ($filesByObjectId as $objectId => $file)
		{
			$groups[$this->needsStrictRights($file) ? 'strict' : 'common'][$objectId] = $file;
		}

		return $groups;
	}

	/**
	 * The kind of rights an object was loaded with, so that the right to download is judged exactly as the
	 * right to read was: a chat storage never gets the administrator bypass, the rest of the supported
	 * storages do.
	 */
	protected function needsStrictRights(BaseObject $object): bool
	{
		$entityType = $object->getStorage()?->getEntityType();

		return \in_array($entityType, self::STRICT_RIGHTS_STORAGE_PROXY_TYPES, true);
	}

	/**
	 * A file answers for its right to download by its real object, so a symlink needs both ids warmed.
	 *
	 * @param array<int, File> $files
	 * @return array<int, int>
	 */
	private function collectRightsObjectIds(array $files): array
	{
		$objectIds = [];
		foreach ($files as $file)
		{
			$objectIds[] = (int)$file->getId();
			$objectIds[] = (int)$file->getRealObjectId();
		}

		return $objectIds;
	}

	private function loadChildrenPage(
		Storage $storage,
		Folder $folder,
		Filter $filter,
		array $order,
		int $offset,
		int $limit,
	): array
	{
		if (!$filter->needsRuntimeFileTypeFiltering())
		{
			return [
				'objects' => $this->collectFilteredPage(
					fn(int $batchOffset, int $batchLimit): array => $this->loadChildrenBatch(
						$storage,
						$folder,
						$filter,
						$order,
						$batchOffset,
						$batchLimit,
					),
					$filter,
					$offset,
					$limit,
				),
				'truncated' => false,
			];
		}

		return $this->collectRuntimeFilteredPage(
			fn(int $batchOffset, int $batchLimit): array => $this->loadChildrenBatch(
				$storage,
				$folder,
				$filter,
				$order,
				$batchOffset,
				$batchLimit,
			),
			$filter,
			$offset,
			$limit,
		);
	}

	private function searchPage(string $query, Filter $filter, ?int $storageId, int $offset, int $limit): array
	{
		if (!$filter->needsRuntimeFileTypeFiltering())
		{
			return [
				'objects' => $this->collectFilteredPage(
					fn(int $batchOffset, int $batchLimit): array => $this->searchBatch(
						$query,
						$filter,
						$storageId,
						$batchOffset,
						$batchLimit,
					),
					$filter,
					$offset,
					$limit,
				),
				'truncated' => false,
			];
		}

		return $this->collectRuntimeFilteredPage(
			fn(int $batchOffset, int $batchLimit): array => $this->searchBatch(
				$query,
				$filter,
				$storageId,
				$batchOffset,
				$batchLimit,
			),
			$filter,
			$offset,
			$limit,
		);
	}

	private function searchBatch(string $query, Filter $filter, ?int $storageId, int $offset, int $limit): array
	{
		$finder = $this->createStorageFileFinder(
			$this->context->getUserId(),
			new StorageFileFinderOptions(
				limit: $limit,
				offset: $offset,
				objectTypes: [ObjectTable::TYPE_FILE, ObjectTable::TYPE_FOLDER],
				storageId: $storageId,
				proxyTypes: [
					ProxyType\User::class,
					ProxyType\Group::class,
					ProxyType\Common::class,
				],
				additionalFilter: $filter->getObjectQueryFilter(),
			),
		);

		return $finder->findModelsByText($query);
	}

	private function loadChildrenBatch(
		Storage $storage,
		Folder $folder,
		Filter $filter,
		array $order,
		int $offset,
		int $limit,
	): array
	{
		$securityContext = $storage->getSecurityContext($this->context->getUserId());
		$queryParameters = [
			'select' => ['*'],
			'filter' => array_merge(
				['=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE],
				$filter->getObjectQueryFilter(),
			),
			'order' => ['TYPE' => 'ASC', ...$order],
			'limit' => $limit,
			'offset' => $offset,
		];

		$loadedObjects = $this->loadChildren($folder, $securityContext, $queryParameters);

		$objects = [];
		foreach ($loadedObjects as $object)
		{
			$objects[] = $object;
		}

		return $objects;
	}

	protected function countChildren(Storage $storage, Folder $folder, Filter $filter): int
	{
		$securityContext = $storage->getSecurityContext($this->context->getUserId());
		$queryParameters = [
			'select' => ['ID'],
			'filter' => array_merge(
				['=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE],
				$filter->getObjectQueryFilter(),
			),
			'limit' => 1,
			'count_total' => true,
		];

		$queryParameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			$securityContext,
			$queryParameters,
			['ID', 'CREATED_BY'],
		);

		return (int)FolderTable::getChildren((int)$folder->getId(), $queryParameters)->getCount();
	}

	private function collectRuntimeFilteredPage(callable $loadBatch, Filter $filter, int $offset, int $limit): array
	{
		$matchedObjects = [];
		$seenIds = [];
		$batchOffset = 0;
		$batchSize = max(self::RUNTIME_FILTER_BATCH_SIZE, $limit);
		$requiredCount = $offset + $limit;
		$scannedCount = 0;
		$truncated = false;

		while (\count($matchedObjects) < $requiredCount)
		{
			$remainingScanCount = self::MAX_RUNTIME_FILTER_SCAN - $scannedCount;
			if ($remainingScanCount <= 0)
			{
				$truncated = true;
				break;
			}

			$batchLimit = min($batchSize, $remainingScanCount);
			$batch = $loadBatch($batchOffset, $batchLimit);
			if (empty($batch))
			{
				break;
			}

			$batchCount = \count($batch);
			$scannedCount += $batchCount;
			$this->appendUniqueObjects($matchedObjects, $seenIds, $this->filterObjects($batch, $filter));

			if ($batchCount < $batchLimit)
			{
				break;
			}

			$batchOffset += $batchCount;
			if (
				$scannedCount >= self::MAX_RUNTIME_FILTER_SCAN
				&& \count($matchedObjects) < $requiredCount
			)
			{
				$truncated = true;
				break;
			}
		}

		return [
			'objects' => \array_slice($matchedObjects, $offset, $limit),
			'truncated' => $truncated,
		];
	}

	private function collectFilteredPage(
		callable $loadBatch,
		Filter $filter,
		int $offset,
		int $limit,
	): array
	{
		$objects = [];
		$seenIds = [];
		$batchOffset = $offset;

		while (\count($objects) < $limit)
		{
			$batchLimit = $limit - \count($objects);
			$batch = $loadBatch($batchOffset, $batchLimit);
			if (empty($batch))
			{
				break;
			}

			$this->appendUniqueObjects($objects, $seenIds, $this->filterObjects($batch, $filter));
			$batchCount = \count($batch);
			if ($batchCount < $batchLimit)
			{
				break;
			}

			$batchOffset += $batchCount;
		}

		return $objects;
	}

	private function appendUniqueObjects(array &$target, array &$seenIds, array $objects): void
	{
		foreach ($objects as $object)
		{
			$objectId = (int)$object->getId();
			if ($objectId <= 0 || isset($seenIds[$objectId]))
			{
				continue;
			}

			$seenIds[$objectId] = true;
			$target[] = $object;
		}
	}

	private function filterObjects(array $objects, Filter $filter): array
	{
		$result = [];
		$seenIds = [];
		foreach ($objects as $object)
		{
			if (!$object instanceof BaseObject)
			{
				continue;
			}

			$objectId = (int)$object->getId();
			if (
				$objectId > 0
				&& !isset($seenIds[$objectId])
				&& $filter->shouldReturnObject($object, $this->fileTypeClassifier)
			)
			{
				$seenIds[$objectId] = true;
				$result[] = $object;
			}
		}

		return $result;
	}

	private function normalizeOrder(array $order): Result
	{
		$result = new Result();
		$fields = [
			self::ORDER_FIELD_NAME => 'NAME',
			self::ORDER_FIELD_CREATE_TIME => 'CREATE_TIME',
			self::ORDER_FIELD_UPDATE_TIME => 'UPDATE_TIME',
		];

		if (
			\count($order) !== 2
			|| !array_key_exists('field', $order)
			|| !array_key_exists('direction', $order)
			|| !is_string($order['field'])
			|| !is_string($order['direction'])
		)
		{
			return $result->addError(Error::create(Error::INVALID_ORDER));
		}

		$field = $order['field'];
		$direction = mb_strtolower($order['direction']);

		if (!isset($fields[$field]) || !\in_array($direction, [self::ORDER_DIRECTION_ASC, self::ORDER_DIRECTION_DESC], true))
		{
			return $result->addError(Error::create(Error::INVALID_ORDER));
		}

		$result->setData([
			'order' => [
				$fields[$field] => mb_strtoupper($direction),
				'ID' => mb_strtoupper($direction),
			],
		]);

		return $result;
	}

	private function normalizePageSize(int $pageSize): int
	{
		if ($pageSize <= 0)
		{
			return self::DEFAULT_PAGE_SIZE;
		}

		return min($pageSize, self::MAX_PAGE_SIZE);
	}

	private function isPageWithinOffsetLimit(int $page, int $pageSize): bool
	{
		return $page > 0
			&& ($page - 1) <= intdiv(self::MAX_PAGE_OFFSET, $pageSize);
	}

	private function isSourcePageValid(int $page, int $pageSize): bool
	{
		return $page > 0
			&& ($page - 1) <= intdiv(PHP_INT_MAX, $pageSize);
	}

	protected function loadReadableFolderContext(int $storageId, int $folderId): ?array
	{
		$folder = $this->loadFolderById($folderId);
		if (!$folder || $folder->isDeleted())
		{
			return null;
		}

		$storage = $folder->getStorage();
		if (!$storage || (int)$storage->getId() !== $storageId)
		{
			return null;
		}

		$securityContext = $storage->getSecurityContext($this->context->getUserId());
		if (!$folder->canRead($securityContext))
		{
			return null;
		}

		return [$storage, $folder, $securityContext];
	}

	protected function loadFolderById(int $folderId): ?Folder
	{
		return Folder::loadById($folderId);
	}

	protected function loadSelectionParentFolderBatch(array $parentIds): iterable
	{
		return Folder::loadBatchById($parentIds);
	}

	protected function loadRecentRecords(int $userId): array
	{
		return (new RecentlyUsedManager())->getFileModelListWithRecentTimeByUser($userId);
	}

	protected function loadSourceStorages(): array
	{
		return $this->getReadableSourceStorages();
	}

	protected function loadStoragesByIds(array $storageIds, array $with = []): array
	{
		return Storage::loadBatchById($storageIds, $with);
	}

	protected function loadReadableObjectsByIds(array $objectIds): iterable
	{
		if (!$this->isStorageAdministrator())
		{
			return $this->loadObjectsForStorageTypes(
				$objectIds,
				self::SUPPORTED_STORAGE_PROXY_TYPES,
				$this->createSecurityContext(),
			);
		}

		$bypassedTypes = array_values(
			array_diff(self::SUPPORTED_STORAGE_PROXY_TYPES, self::STRICT_RIGHTS_STORAGE_PROXY_TYPES),
		);

		return [
			...$this->loadObjectsForStorageTypes(
				$objectIds,
				self::STRICT_RIGHTS_STORAGE_PROXY_TYPES,
				$this->createSecurityContext(),
			),
			...$this->loadObjectsForStorageTypes(
				$objectIds,
				$bypassedTypes,
				$this->createResolveSelectionSecurityContext(),
			),
		];
	}

	private function loadObjectsForStorageTypes(
		array $objectIds,
		array $storageProxyTypes,
		SecurityContext $securityContext,
	): array
	{
		$parameters = [
			'select' => ['*'],
			'filter' => [
				'@ID' => $objectIds,
				'@STORAGE.ENTITY_TYPE' => $storageProxyTypes,
			],
			'with' => ['STORAGE'],
		];
		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			$securityContext,
			$parameters,
			['ID', 'CREATED_BY'],
		);

		$objects = $this->loadObjects($parameters);

		return is_array($objects) ? $objects : iterator_to_array($objects);
	}

	protected function loadObjects(array $parameters): iterable
	{
		return BaseObject::getModelList($parameters);
	}

	protected function loadGroupTitles(array $groupIds): array
	{
		$titles = [];
		foreach (array_chunk($groupIds, self::WARM_UP_BATCH_SIZE) as $groupIdBatch)
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
				$this->groupSourceAvatarUrls[$groupId] = $avatarUrls[$imageId] ?? null;
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
				\Bitrix\Main\Context::getCurrent()->getRequest()->isAjaxRequest(),
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

	protected function loadChildren(
		Folder $folder,
		SecurityContext $securityContext,
		array $queryParameters,
	): iterable
	{
		return $folder->getChildren($securityContext, $queryParameters);
	}

	protected function loadParentFolders(Folder $folder, SecurityContext $securityContext): array
	{
		return $folder->getParents(
			$securityContext,
			['select' => ['ID', 'NAME', 'TYPE', 'STORAGE_ID']],
			SORT_DESC,
		);
	}

	protected function createStorageFileFinder(
		int $userId,
		StorageFileFinderOptions $options,
	): StorageFileFinder
	{
		return new StorageFileFinder(
			$userId,
			options: $options,
		);
	}

	private function getEmptyNavigationContext(): array
	{
		return [
			'storageId' => null,
			'folderId' => null,
			'currentFolder' => null,
			'breadcrumbs' => [],
		];
	}

	private function uniquePositiveIds(array $objectIds): array
	{
		$ids = [];
		$seenIds = [];

		foreach ($objectIds as $objectId)
		{
			if (
				!\is_int($objectId)
				&& !\is_float($objectId)
				&& !\is_string($objectId)
				&& !\is_bool($objectId)
			)
			{
				continue;
			}

			$objectId = (int)$objectId;
			if ($objectId <= 0 || isset($seenIds[$objectId]))
			{
				continue;
			}

			$seenIds[$objectId] = true;
			$ids[] = $objectId;
		}

		return $ids;
	}
}
