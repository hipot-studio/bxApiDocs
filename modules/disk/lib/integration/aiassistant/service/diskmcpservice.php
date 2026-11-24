<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Service;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\Driver;
use Bitrix\Disk\UrlManager;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Repository\ExternalLinkBitrixOrmRepository;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkAccessService;
use Bitrix\Disk\Internal\Service\Folder\DeletionRestriction;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Internals\ObjectNameKey;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\VersionTable;
use Bitrix\Disk\ProxyType;
use Bitrix\Disk\Public\Provider\ExternalLinkTariffProvider;
use Bitrix\Disk\RightsManager;
use Bitrix\Disk\Search\StorageFileFinder;
use Bitrix\Disk\Search\StorageFileFinderOptions;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Sharing;
use Bitrix\Disk\Storage;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Ui\Destination;
use Bitrix\Disk\Version;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class DiskMcpService
{
	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT = 50;
	private const MAX_OFFSET = 500;
	private const MAX_DELETE_FILE_IDS = 100;
	private const MAX_CREATE_ITEMS = 20;
	private const MAX_MUTATION_ITEMS = 50;
	private const MAX_UPLOAD_ITEM_SIZE = 10485760;
	private const MAX_UPLOAD_TOTAL_SIZE = 20971520;
	private const MAX_FOLDER_COPY_OBJECTS = 100;
	private const MAX_FOLDER_DELETE_OBJECTS = 500;
	private const MAX_FOLDER_DELETE_REQUEST_OBJECTS = 500;
	private const MAX_TRASH_ITEMS = 100;
	private const MAX_TRASH_SUMMARY_ITEMS = 1000;
	private const TRASH_FOLDER_METRICS_CHUNK_SIZE = 500;
	private const GLOBAL_SEARCH_SCAN_LIMIT = 500;
	private const CLEANUP_SOURCE_SCAN_LIMIT = 500;
	private const DUPLICATE_SCAN_LIMIT = 500;
	private const DEPARTMENT_USER_SCAN_LIMIT = 500;
	protected const STORAGE_NAME_SCAN_LIMIT = 100;
	private const MIN_USER_SEARCH_QUERY_LENGTH = 3;
	private ?FolderAccessManager $folderAccessManager;
	private ?SupportedStoragePolicy $supportedStoragePolicy;
	private ?TrashService $trashService;
	private ?StorageUsageService $storageUsageService;
	private ?ExternalLinkAccessService $externalLinkAccessService = null;
	private ?ExternalLinkTariffProvider $externalLinkTariffProvider = null;

	public function __construct(
		?FolderAccessManager $folderAccessManager = null,
		?SupportedStoragePolicy $supportedStoragePolicy = null,
		?TrashService $trashService = null,
		?StorageUsageService $storageUsageService = null,
	)
	{
		$this->folderAccessManager = $folderAccessManager;
		$this->supportedStoragePolicy = $supportedStoragePolicy;
		$this->trashService = $trashService;
		$this->storageUsageService = $storageUsageService;
	}

	public function getFileLink(
		int $userId,
		?int $fileId,
		?string $fileName,
		?int $storageId,
		?int $folderId,
	): array
	{
		$file = $this->resolveFile($userId, $fileId, $fileName, $storageId, $folderId);
		if (is_array($file))
		{
			return $file;
		}

		$item = $this->formatObject($file);
		$internalLink = $item['internalLink'];
		if ($internalLink === null)
		{
			return [
				'file' => $item,
				'internalLink' => null,
				'resultMeta' => $this->buildResultMeta(
					false,
					[],
					'unavailable',
					[['code' => 'INTERNAL_LINK_UNAVAILABLE']],
				),
			];
		}

		return [
			'file' => $item,
			'internalLink' => $internalLink,
			'resultMeta' => $this->buildResultMeta(false),
		];
	}

	public function uploadFiles(
		int $userId,
		array $files,
		?int $folderId,
		?int $storageId,
		bool $confirmed = false,
	): array
	{
		$this->validateItemCount($files, self::MAX_CREATE_ITEMS, 'files');
		$preparedFiles = $this->prepareUploadFiles($files);
		$targetFolder = $this->resolveWriteTarget($folderId, $storageId, 'upload_files');
		$this->ensureCanAdd($userId, $targetFolder, 'upload files');

		$preparedItems = [];
		foreach ($preparedFiles as $index => $file)
		{
			$preparedItems[] = [
				'index' => $index,
				'name' => $file['name'],
				'contentType' => $file['contentType'],
				'size' => strlen($file['content']),
				'status' => $this->hasNameConflict($targetFolder, $file['name'], 'file')
					? 'name_conflict'
					: 'ready',
			];
		}

		if ($this->requiresConfirmation($userId, [$targetFolder], count($preparedFiles)) && !$confirmed)
		{
			return $this->buildConfirmationResponse('upload_files', $preparedItems, $targetFolder);
		}

		$items = [];
		$itemOutcomes = [];
		foreach ($preparedFiles as $index => $file)
		{
			if ($preparedItems[$index]['status'] !== 'ready')
			{
				$itemOutcomes[] = $this->buildCreationOutcome($preparedItems[$index]);

				continue;
			}

			$tempFile = null;
			try
			{
				$tempFile = $this->writeTemporaryUpload($file['content']);
				$uploadedFile = $targetFolder->uploadFile(
					[
						'name' => $file['name'],
						'type' => $file['contentType'],
						'tmp_name' => $tempFile,
						'error' => UPLOAD_ERR_OK,
						'size' => strlen($file['content']),
					],
					[
						'NAME' => $file['name'],
						'CREATED_BY' => $userId,
					],
					[],
					false,
				);
				if (!$uploadedFile)
				{
					$itemOutcomes[] = $this->buildFailedCreationOutcome($index, $file['name']);

					continue;
				}

				$item = $this->formatObject($uploadedFile);
				$items[] = $item;
				$itemOutcomes[] = [
					'index' => $index,
					'name' => $file['name'],
					'status' => 'success',
					'item' => $item,
				];
			}
			catch (\Throwable)
			{
				$itemOutcomes[] = [
					'index' => $index,
					'name' => $file['name'],
					'status' => 'unknown_result',
					'errorCode' => 'DISK_UPLOAD_RESULT_UNKNOWN',
				];
			}
			finally
			{
				$this->deleteTemporaryUpload($tempFile);
			}
		}

		return $this->buildMutationResponse('upload_files', $items, $itemOutcomes, $targetFolder);
	}

	public function createFolders(
		int $userId,
		array $names,
		?int $folderId,
		?int $storageId,
		bool $confirmed = false,
	): array
	{
		$this->validateItemCount($names, self::MAX_CREATE_ITEMS, 'names');
		$names = $this->normalizeNames($names);
		$targetFolder = $this->resolveCreateFoldersTarget($userId, $folderId, $storageId);
		$this->ensureCanAdd($userId, $targetFolder, 'create folders');

		$preparedItems = [];
		foreach ($names as $index => $name)
		{
			$preparedItems[] = [
				'index' => $index,
				'name' => $name,
				'size' => null,
				'status' => $this->hasNameConflict($targetFolder, $name, 'folder') ? 'name_conflict' : 'ready',
			];
		}

		if ($this->requiresConfirmation($userId, [$targetFolder], count($names)) && !$confirmed)
		{
			return $this->buildConfirmationResponse('create_folders', $preparedItems, $targetFolder);
		}

		$items = [];
		$itemOutcomes = [];
		foreach ($names as $index => $name)
		{
			if ($preparedItems[$index]['status'] !== 'ready')
			{
				$itemOutcomes[] = $this->buildCreationOutcome($preparedItems[$index]);

				continue;
			}

			try
			{
				$folder = $targetFolder->addSubFolder(
					[
						'NAME' => $name,
						'CREATED_BY' => $userId,
					],
					[],
					false,
				);
				if (!$folder)
				{
					$itemOutcomes[] = $this->buildFailedCreationOutcome($index, $name);

					continue;
				}

				$item = $this->formatObject($folder);
				$items[] = $item;
				$itemOutcomes[] = [
					'index' => $index,
					'name' => $name,
					'status' => 'success',
					'item' => $item,
				];
			}
			catch (\Throwable)
			{
				$itemOutcomes[] = [
					'index' => $index,
					'name' => $name,
					'status' => 'unknown_result',
					'errorCode' => 'DISK_FOLDER_CREATION_RESULT_UNKNOWN',
				];
			}
		}

		return $this->buildMutationResponse('create_folders', $items, $itemOutcomes, $targetFolder);
	}

	public function renameObjects(
		int $userId,
		string $objectType,
		array $items,
		bool $confirmed = false,
	): array
	{
		return $this->organizeObjects($userId, 'rename', $objectType, $items, null, $confirmed);
	}

	public function moveObjects(
		int $userId,
		string $objectType,
		array $objectIds,
		?int $targetFolderId,
		?int $targetStorageId,
		bool $confirmed = false,
	): array
	{
		$items = array_map(static fn(int $objectId): array => ['id' => $objectId], $objectIds);
		$targetFolder = $this->resolveWriteTarget($targetFolderId, $targetStorageId, "move_{$objectType}s");

		return $this->organizeObjects($userId, 'move', $objectType, $items, $targetFolder, $confirmed);
	}

	public function copyObjects(
		int $userId,
		string $objectType,
		array $objectIds,
		?int $targetFolderId,
		?int $targetStorageId,
		bool $confirmed = false,
	): array
	{
		$items = array_map(static fn(int $objectId): array => ['id' => $objectId], $objectIds);
		$targetFolder = $this->resolveWriteTarget($targetFolderId, $targetStorageId, "copy_{$objectType}s");

		return $this->organizeObjects($userId, 'copy', $objectType, $items, $targetFolder, $confirmed);
	}

	public function searchFiles(int $userId, string $searchType, array $options): array
	{
		$limit = $this->normalizeLimit($options['limit'] ?? self::DEFAULT_LIMIT);
		$offset = $this->normalizeOffset((int)($options['offset'] ?? 0));
		$fileType = mb_strtolower(trim((string)($options['fileType'] ?? '')));
		$extension = mb_strtolower(ltrim(trim((string)($options['extension'] ?? '')), '.'));
		$hasFiniteScope = !empty($options['storageId']) || !empty($options['folderId']) || !empty($options['storageIds']);
		$hasOwnerConstraint = (int)($options['ownerId'] ?? 0) > 0
			|| trim((string)($options['ownerName'] ?? '')) !== ''
		;
		$hasFileFilter = $extension !== '' || !in_array($fileType, ['', 'any'], true);
		$hasBoundedBroadFileTypePath = $extension === ''
			&& in_array($fileType, ['video', 'audio', 'pdf', 'document'], true)
			&& $searchType === 'uploaded_recently'
			&& $hasOwnerConstraint
		;
		$requiresByTypeScope = $searchType === 'by_type'
			&& ($extension !== '' || $fileType !== '')
			&& !$hasFiniteScope
		;
		$requiresGlobalDateScope = !$hasFiniteScope
			&& in_array($searchType, ['uploaded_recently', 'by_owner'], true)
			&& !$hasBoundedBroadFileTypePath
		;
		if (
			$requiresByTypeScope
			|| $requiresGlobalDateScope
			|| ($hasFileFilter && !$hasFiniteScope && !$hasBoundedBroadFileTypePath)
		)
		{
			if ($searchType === 'by_type')
			{
				$message = 'by_type wildcard search requires storageId, folderId or storageIds.';
			}
			elseif ($extension !== '' || $fileType === 'text')
			{
				$message = 'extension and text MIME searches require storageId, folderId or storageIds.';
			}
			elseif ($requiresGlobalDateScope)
			{
				$message = 'Global uploaded_recently and by_owner searches require a finite storage scope unless '
					. 'uploaded_recently uses an owner with video, audio, pdf or document fileType.';
			}
			else
			{
				$message = 'Broad file type search requires uploaded_recently with an owner, storageId, folderId '
					. 'or storageIds.';
			}
			throw new McpException(
				$message,
			);
		}
		$limitations = $this->prepareSearchStorageScope($userId, $options);
		$filter = $this->buildObjectScopeFilter($userId, $options);
		$order = ['ID' => 'DESC'];
		$note = null;

		if (!empty($options['periodDays']) && (!empty($options['dateFrom']) || !empty($options['dateTo'])))
		{
			throw new McpException('periodDays cannot be combined with dateFrom or dateTo.');
		}

		$ownerResolution = $this->resolveSearchOwner($userId, $options);
		if (isset($ownerResolution['response']))
		{
			return $ownerResolution['response'];
		}
		if (isset($ownerResolution['userId']))
		{
			$filter['=CREATED_BY'] = $ownerResolution['userId'];
		}

		$departmentResolution = $this->resolveSearchDepartment($userId, $options);
		if (isset($departmentResolution['response']))
		{
			return $departmentResolution['response'];
		}
		if (isset($departmentResolution['userIds']))
		{
			$filter['@CREATED_BY'] = $departmentResolution['userIds'];
			$limitations = array_merge($limitations, $departmentResolution['limitations']);
		}

		$this->applyFileTypeFilter($filter, $options);
		$dateField = $searchType === 'uploaded_recently' ? 'CREATE_TIME' : 'UPDATE_TIME';
		if ($searchType !== 'unused')
		{
			$this->applyDateFilter($filter, $options, $dateField);
		}

		if ($searchType === 'by_name')
		{
			$query = (string)($options['query'] ?? '');
			if ($query === '')
			{
				throw new McpException('query is required for by_name search.');
			}

			$filter['=TYPE'] = ObjectTable::TYPE_FILE;

			$limitations[] = ['code' => 'SEARCH_INDEX_COMPLETENESS_UNKNOWN'];
			$result = $this->searchFilesByName($userId, $query, $filter, $limit, $offset);
			$limitations = array_merge(
				$limitations,
				$this->collectInternalLinkLimitations($result['items'] ?? []),
			);
			$result['resultMeta'] = $this->buildResultMeta($result['hasMore'], $limitations);

			return $result;
		}
		if ($searchType === 'top_by_size')
		{
			if (empty($options['storageId']) && empty($options['folderId']))
			{
				throw new McpException('top_by_size requires storageId or folderId to avoid sorting all Disk files.');
			}

			$filter['=TYPE'] = ObjectTable::TYPE_FILE;
			$order = ['SIZE' => 'DESC', 'ID' => 'DESC'];
		}
		elseif ($searchType === 'unused')
		{
			$periodDays = (int)($options['periodDays'] ?? 90);
			if ($periodDays <= 0)
			{
				throw new McpException('periodDays must be a positive integer for unused search.');
			}

			$filter['=TYPE'] = ObjectTable::TYPE_FILE;
			$filter['<=UPDATE_TIME'] = DateTime::createFromTimestamp(time() - $periodDays * 86400);
			$order = ['UPDATE_TIME' => 'ASC', 'ID' => 'DESC'];
			$note = 'Unused files are detected by UPDATE_TIME / last modified time. Disk API does not provide last opened time.';
		}
		elseif (in_array($searchType, ['recent', 'uploaded_recently', 'by_owner', 'by_department', 'by_type'], true))
		{
			if ($searchType === 'by_owner' && !isset($ownerResolution['userId']))
			{
				throw new McpException('by_owner requires ownerId or ownerName.');
			}
			if ($searchType === 'by_department' && !isset($departmentResolution['userIds']))
			{
				throw new McpException('by_department requires departmentId or departmentName.');
			}
			if ($searchType === 'by_type' && empty($options['extension']) && empty($options['fileType']))
			{
				throw new McpException('by_type requires extension or fileType.');
			}

			$filter['=TYPE'] = ObjectTable::TYPE_FILE;
			$order = [$dateField => 'DESC', 'ID' => 'DESC'];
		}
		elseif ($searchType === 'with_public_links')
		{
			if (empty($options['storageId']) && empty($options['folderId']))
			{
				throw new McpException('with_public_links requires storageId or folderId to avoid scanning all Disk files.');
			}

			$filter['=TYPE'] = ObjectTable::TYPE_FILE;
			$filter['=PUBLIC_LINK.TYPE'] = ExternalLinkTable::TYPE_MANUAL;
			$filter['=PUBLIC_LINK.IS_EXPIRED'] = false;
		}
		elseif ($searchType === 'duplicates')
		{
			if (empty($options['storageId']) && empty($options['folderId']))
			{
				throw new McpException('duplicates requires storageId or folderId to avoid sorting all Disk files.');
			}

			$filter['=TYPE'] = ObjectTable::TYPE_FILE;

			$result = $this->searchDuplicates($userId, $filter, $limit, $offset);
			$limitations = array_merge($limitations, $result['limitations']);
			$result['resultMeta'] = $this->buildResultMeta($result['hasMore'], $limitations);

			return $result;
		}
		else
		{
			throw new McpException("Unknown searchType '{$searchType}'.");
		}

		if ($hasBoundedBroadFileTypePath && !$hasFiniteScope)
		{
			$result = $this->searchFilesInGlobalWindow($userId, $filter, $limit, $offset);
			$limitations = array_merge($limitations, $result['limitations']);
			unset($result['limitations']);
			$result['resultMeta'] = $this->buildResultMeta($result['hasMore'], $limitations);

			return $result;
		}

		try
		{
			$result = $this->getObjectsPage(
				$userId,
				$filter,
				$order,
				$limit,
				$offset,
				$searchType === 'with_public_links',
			);
		}
		catch (\Throwable $exception)
		{
			if ($searchType !== 'with_public_links')
			{
				throw $exception;
			}

			return [
				'items' => [],
				'limit' => $limit,
				'offset' => $offset,
				'hasMore' => false,
				'resultMeta' => $this->buildResultMeta(false, [[
					'code' => 'source_unavailable',
					'source' => 'public_links',
				]]),
			];
		}
		$limitations = array_merge(
			$limitations,
			$this->collectInternalLinkLimitations($result['items'] ?? []),
		);
		$result['resultMeta'] = $this->buildResultMeta($result['hasMore'], $limitations);
		if ($note !== null)
		{
			$result['note'] = $note;
		}

		return $result;
	}

	public function getFolderContents(
		int $userId,
		?int $folderId,
		?string $folderName,
		?int $storageId,
		int $limit,
		int $offset,
		?string $storageName = null,
	): array
	{
		$folder = $this->resolveFolderOrStorageRoot($userId, $folderId, $folderName, $storageId, $storageName);
		if (is_array($folder))
		{
			return $folder;
		}
		$limit = $this->normalizeLimit($limit);
		$offset = max(0, $offset);

		$securityContext = $folder->getStorage()->getSecurityContext($userId);
		if (!$folder->canRead($securityContext))
		{
			throw new McpException("Access denied for folder {$folder->getId()}.");
		}

		$children = $folder->getChildren($securityContext, [
			'limit' => $limit + 1,
			'offset' => $offset,
			'order' => ['TYPE' => 'ASC', 'NAME' => 'ASC'],
		]);

		$hasMore = count($children) > $limit;
		$items = $this->formatObjects(array_slice($children, 0, $limit));
		$limitations = $this->collectInternalLinkLimitations($items);

		return [
			'folder' => $this->formatObject($folder),
			'items' => $items,
			'scope' => 'direct_children',
			'limit' => $limit,
			'offset' => $offset,
			'hasMore' => $hasMore,
			'resultMeta' => $this->buildResultMeta($hasMore, $limitations),
		];
	}

	public function getFileHistory(
		int $userId,
		?int $fileId,
		?string $fileName,
		?int $storageId,
		?int $folderId,
		string $mode,
		?int $periodDays,
		bool $asksAboutComments,
		int $limit,
		int $offset,
	): array
	{
		if ($asksAboutComments)
		{
			return [
				'supported' => false,
				'message' => 'Inline editor comments are not available through Disk API. Version history is available.',
				'resultMeta' => $this->buildResultMeta(false, [[
					'code' => 'EDITOR_COMMENTS_UNSUPPORTED',
				]], 'unsupported'),
			];
		}

		$file = $this->resolveFile($userId, $fileId, $fileName, $storageId, $folderId);
		if (is_array($file))
		{
			return $file;
		}
		$securityContext = $file->getStorage()->getSecurityContext($userId);
		if (!$file->canRead($securityContext))
		{
			throw new McpException("Access denied for file {$file->getId()}.");
		}

		$limit = $this->normalizeLimit($limit);
		$offset = max(0, $offset);
		$filter = ['=OBJECT_ID' => $file->getId()];
		if ($periodDays !== null)
		{
			$filter['>=CREATE_TIME'] = DateTime::createFromTimestamp(time() - $periodDays * 86400);
		}

		$rows = VersionTable::getList([
			'select' => [
				'ID',
				'OBJECT_ID',
				'NAME',
				'SIZE',
				'CREATE_TIME',
				'CREATED_BY',
				'CREATED_BY_NAME' => 'CREATE_USER.NAME',
				'CREATED_BY_LAST_NAME' => 'CREATE_USER.LAST_NAME',
			],
			'filter' => $filter,
			'order' => ['CREATE_TIME' => 'DESC', 'FILE_ID' => 'DESC'],
			'limit' => $limit + 1,
			'offset' => $offset,
		])->fetchAll();

		$versions = array_map(fn(array $row): array => $this->formatVersion($row), array_slice($rows, 0, $limit));
		$response = [
			'file' => $this->formatObject($file),
			'mode' => $mode,
			'limit' => $limit,
			'offset' => $offset,
			'hasMore' => count($rows) > $limit,
		];
		$response['resultMeta'] = $this->buildResultMeta($response['hasMore']);

		if ($mode === 'editors')
		{
			$response['editors'] = $this->buildEditors($versions);
			$response['editorStats'] = [
				'scope' => 'current_page',
				'isComplete' => $offset === 0 && !$response['hasMore'],
				'versionsOffset' => $offset,
				'versionsLimit' => $limit,
				'versionsCounted' => count($versions),
				'limitations' => $offset === 0 && !$response['hasMore'] ? [] : [[
					'code' => 'EDITOR_STATS_PAGE_ONLY',
				]],
			];
		}
		elseif ($mode === 'full' || $mode === 'timeline')
		{
			$response['versions'] = $versions;
		}
		else
		{
			throw new McpException("Unknown history mode '{$mode}'.");
		}

		return $response;
	}

	public function restoreFileVersion(
		int $userId,
		int $fileId,
		?int $versionId,
		?string $versionDate,
		bool $confirmed = false,
	): array
	{
		if ($fileId <= 0)
		{
			throw new McpException('fileId must be greater than zero.');
		}
		if (($versionId === null) === ($versionDate === null))
		{
			throw new McpException('Pass exactly one versionId or versionDate.');
		}

		$file = $this->resolveFile($userId, $fileId, null, null, null);
		if (is_array($file))
		{
			return $file;
		}
		$securityContext = $file->getStorage()->getSecurityContext($userId);
		if (!$file->canManageVersions($securityContext))
		{
			throw new McpException("Access denied to manage versions of file {$fileId}.");
		}

		$version = $versionId !== null
			? $file->getVersion($versionId)
			: $this->resolveFileVersionByDate($file, (string)$versionDate)
		;
		if (!$version)
		{
			throw new McpException("Version {$versionId} does not belong to file {$fileId}.");
		}
		$versionItem = $this->formatVersionModel($version);
		if ($this->requiresConfirmation($userId, [$file], 1) && !$confirmed)
		{
			return $this->buildConfirmationResponse('restore_file_version', [[
				'fileId' => $fileId,
				'version' => $versionItem,
				'status' => 'ready',
			]]);
		}

		$versionsBefore = VersionTable::getCount(['=OBJECT_ID' => $fileId]);
		try
		{
			if (!$file->restoreFromVersion($version, $userId))
			{
				return [
					'file' => $this->formatObject($file),
					'restoredVersion' => $versionItem,
					'success' => false,
					'confirmationRequired' => false,
					'itemOutcomes' => [[
						'fileId' => $fileId,
						'versionId' => (int)$version->getId(),
						'status' => 'failed',
						'errorCode' => 'DISK_VERSION_RESTORE_FAILED',
					]],
					'resultMeta' => $this->buildResultMeta(false, [], 'failed'),
				];
			}
		}
		catch (\Throwable)
		{
			return [
				'fileId' => $fileId,
				'restoredVersion' => $versionItem,
				'success' => false,
				'confirmationRequired' => false,
				'retrySafe' => false,
				'itemOutcomes' => [[
					'fileId' => $fileId,
					'versionId' => (int)$version->getId(),
					'status' => 'unknown_result',
					'errorCode' => 'DISK_VERSION_RESTORE_RESULT_UNKNOWN',
				]],
				'resultMeta' => $this->buildResultMeta(false, [], 'unknown_result'),
			];
		}

		$restoredFile = File::loadById($fileId);
		$versionsAfter = VersionTable::getCount(['=OBJECT_ID' => $fileId]);
		$chainPreserved = $versionsAfter > $versionsBefore && $file->getVersion($version->getId()) !== null;
		if (!$restoredFile || !$chainPreserved)
		{
			return [
				'fileId' => $fileId,
				'restoredVersion' => $versionItem,
				'success' => false,
				'confirmationRequired' => false,
				'retrySafe' => false,
				'itemOutcomes' => [[
					'fileId' => $fileId,
					'versionId' => (int)$version->getId(),
					'status' => 'unknown_result',
					'errorCode' => 'DISK_VERSION_RESTORE_RESULT_UNKNOWN',
				]],
				'resultMeta' => $this->buildResultMeta(false, [], 'unknown_result'),
			];
		}

		return [
			'file' => $this->formatObject($restoredFile),
			'restoredVersion' => $versionItem,
			'success' => true,
			'confirmationRequired' => false,
			'chainPreserved' => true,
			'itemOutcomes' => [[
				'fileId' => $fileId,
				'versionId' => (int)$version->getId(),
				'status' => 'success',
			]],
			'resultMeta' => $this->buildResultMeta(false),
		];
	}

	public function createPublicLink(int $userId, int $fileId, bool $confirmed = false): array
	{
		if ($fileId <= 0)
		{
			throw new McpException('fileId must be greater than zero.');
		}

		$file = $this->resolveFile($userId, $fileId, null, null, null);
		if (is_array($file))
		{
			return $file;
		}
		$securityContext = $file->getStorage()->getSecurityContext($userId);
		if (!$file->canManageExternalLink($securityContext))
		{
			throw new McpException("Access denied to manage a public link for file {$fileId}.");
		}

		if (!$this->getExternalLinkAccessService()->isExternalLinkEnabledOnPortal())
		{
			$this->assertPublicLinkExistsWhileDisabledOnPortal($file);
		}

		if (!$confirmed)
		{
			return $this->buildConfirmationResponse('create_public_link', [[
				'fileId' => $fileId,
				'file' => $this->formatObject($file),
				'status' => 'ready',
			]]);
		}

		$repository = new ExternalLinkBitrixOrmRepository();
		try
		{
			$externalLink = $repository->getForUse($file->getRealObjectId());
		}
		catch (\Throwable)
		{
			return $this->buildUnknownPublicLinkResponse($fileId);
		}

		if ($externalLink)
		{
			return $this->buildPublicLinkResponse($file, $externalLink, false);
		}

		if (!$this->getExternalLinkAccessService()->isExternalLinkEnabledOnPortal())
		{
			throw $this->buildPublicLinksDisabledOnPortalException($file);
		}

		if (!$this->getExternalLinkTariffProvider()->isManualLinkCreationAllowed($file))
		{
			throw $this->buildPublicLinksDisabledByTariffException($file);
		}

		try
		{
			$externalLink = $file->getRealObject()->addExternalLink([
				'CREATED_BY' => $userId,
				'TYPE' => ExternalLinkTable::TYPE_MANUAL,
			]);
			if ($externalLink)
			{
				return $this->buildPublicLinkResponse($file, $externalLink, true);
			}
		}
		catch (\Throwable)
		{
		}

		try
		{
			$externalLink = $repository->getForUse($file->getRealObjectId());
		}
		catch (\Throwable)
		{
			return $this->buildUnknownPublicLinkResponse($fileId);
		}

		return $externalLink
			? $this->buildPublicLinkResponse($file, $externalLink, true)
			: [
				'fileId' => $fileId,
				'publicLink' => null,
				'success' => false,
				'confirmationRequired' => false,
				'retrySafe' => true,
				'itemOutcomes' => [[
					'fileId' => $fileId,
					'status' => 'absent',
					'errorCode' => 'PUBLIC_LINK_ABSENT',
				]],
				'resultMeta' => $this->buildResultMeta(false, [], 'absent'),
			]
		;
	}

	public function getCleanupCandidates(
		int $userId,
		int $storageId,
		array $categories,
		string $strategy,
		int $serverLimit,
	): array
	{
		$allowedCategories = ['duplicate', 'old_large_copy', 'unnecessary_version', 'trash'];
		$categories = array_values(array_unique($categories ?: $allowedCategories));
		if (array_diff($categories, $allowedCategories))
		{
			throw new McpException('Unknown cleanup category.');
		}
		if (!in_array($strategy, ['safe', 'largest_first', 'oldest_first'], true))
		{
			throw new McpException("Unknown cleanup strategy '{$strategy}'.");
		}
		$serverLimit = max(1, min(self::MAX_TRASH_ITEMS, $serverLimit));
		$storage = $this->loadSupportedStorage($storageId, 'get_cleanup_candidates');
		$rootFolder = $storage->getRootObject();
		if (!$rootFolder || !$rootFolder->canRead($storage->getSecurityContext($userId)))
		{
			throw new McpException("Access denied for storage {$storageId}.");
		}

		$candidates = [];
		$limitations = [];
		foreach ($categories as $category)
		{
			try
			{
				$source = match ($category)
				{
					'duplicate' => $this->collectDuplicateCandidates($userId, $storage, $serverLimit),
					'old_large_copy' => $this->collectOldLargeCandidates($userId, $storage, $serverLimit),
					'unnecessary_version' => $this->collectVersionCandidates($userId, $storage, $serverLimit),
					'trash' => $this->collectTrashCandidates($userId, $storage, $serverLimit),
				};
				$candidates = array_merge($candidates, $source['items']);
				if ($source['truncated'])
				{
					$limitations[] = ['code' => 'CLEANUP_SOURCE_TRUNCATED', 'source' => $category];
				}
			}
			catch (\Throwable)
			{
				$limitations[] = ['code' => 'CLEANUP_SOURCE_UNAVAILABLE', 'source' => $category];
			}
		}

		$candidates = $this->deduplicateCleanupCandidates($candidates);
		$this->sortCleanupCandidates($candidates, $strategy);
		$hasMore = count($candidates) > $serverLimit;
		if ($hasMore)
		{
			$limitations[] = ['code' => 'CLEANUP_RESULT_TRUNCATED'];
		}
		$candidates = array_slice($candidates, 0, $serverLimit);

		return [
			'items' => $candidates,
			'strategy' => $strategy,
			'serverLimit' => $serverLimit,
			'hasMore' => $hasMore,
			'resultMeta' => $this->buildResultMeta($hasMore, $limitations),
		];
	}

	public function deleteFolders(
		int $userId,
		array $folderIds,
		string $deletionType,
		bool $confirmed = false,
	): array
	{
		$this->validateItemCount($folderIds, self::MAX_CREATE_ITEMS, 'folderIds');
		if (!in_array($deletionType, ['soft', 'permanent'], true))
		{
			throw new McpException("Unknown folder deletion type '{$deletionType}'.");
		}
		$folderIds = array_map('intval', $folderIds);
		if (min($folderIds) <= 0 || count($folderIds) !== count(array_unique($folderIds)))
		{
			throw new McpException('folderIds must contain unique positive IDs.');
		}

		$foldersById = [];
		foreach (Folder::getModelList([
			'select' => ['*'],
			'filter' => ['@ID' => $folderIds, '=TYPE' => ObjectTable::TYPE_FOLDER],
			'with' => ['STORAGE'],
		]) as $folder)
		{
			$foldersById[(int)$folder->getId()] = $folder;
		}

		$preparedItems = [];
		$requestTreeObjectIds = [];
		foreach ($folderIds as $index => $folderId)
		{
			$preparedItem = $this->prepareFolderDeletion(
				$foldersById[$folderId] ?? null,
				$folderId,
				$deletionType,
				$index,
			);
			$preparedItems[] = $preparedItem;
			if ($preparedItem['errorCode'] === null)
			{
				foreach ($preparedItem['treeObjectIds'] as $objectId)
				{
					$requestTreeObjectIds[$objectId] = true;
				}
			}
		}
		if (count($requestTreeObjectIds) > self::MAX_FOLDER_DELETE_REQUEST_OBJECTS)
		{
			foreach ($preparedItems as &$preparedItem)
			{
				if ($preparedItem['errorCode'] === null)
				{
					$preparedItem['errorCode'] = 'LIMIT_EXCEEDED';
					$preparedItem['preview']['status'] = 'limit_exceeded';
				}
			}
			unset($preparedItem);
		}

		$hasScopeFailure = (bool)array_filter(
			$preparedItems,
			static fn(array $item): bool => $item['errorCode'] !== null,
		);
		if (!$hasScopeFailure)
		{
			$objectsById = [];
			foreach (BaseObject::loadBatchById(array_keys($requestTreeObjectIds), ['STORAGE']) as $object)
			{
				$objectsById[(int)$object->getId()] = $object;
			}
			$securityContextsByStorageId = $this->preloadFolderDeletionOperations($objectsById, $userId);
			foreach ($preparedItems as &$preparedItem)
			{
				/** @var Folder $folder */
				$folder = $preparedItem['folder'];
				$securityContext = $securityContextsByStorageId[(int)$folder->getStorageId()]
					?? $folder->getStorage()->getSecurityContext($userId)
				;
				$this->validateFolderDeletionTree(
					$preparedItem,
					$objectsById,
					$userId,
					$deletionType,
					$securityContext,
				);
			}
			unset($preparedItem);
		}
		$failedItems = array_filter(
			$preparedItems,
			static fn(array $item): bool => $item['preview']['status'] !== 'ready',
		);
		if ($failedItems)
		{
			return $this->buildFolderDeletionFailure($deletionType, $preparedItems);
		}
		if (!$confirmed)
		{
			return $this->buildConfirmationResponse(
				'delete_folders',
				array_column($preparedItems, 'preview'),
			);
		}

		$itemOutcomes = [];
		$stopAfterUnknown = false;
		foreach ($preparedItems as $preparedItem)
		{
			$preview = $preparedItem['preview'];
			if ($stopAfterUnknown)
			{
				$preview['status'] = 'not_attempted_after_unknown_result';
				$itemOutcomes[] = $preview;

				continue;
			}
			try
			{
				/** @var Folder $folder */
				$folder = $preparedItem['folder'];
				$success = $deletionType === 'permanent'
					? $folder->deleteTree($userId)
					: $folder->markDeleted($userId)
				;
				$preview['status'] = $success ? 'success' : 'failed';
				if (!$success)
				{
					$preview['errorCode'] = 'DISK_FOLDER_DELETION_FAILED';
				}
				$itemOutcomes[] = $preview;
			}
			catch (\Throwable)
			{
				$preview['status'] = 'unknown_result';
				$preview['errorCode'] = 'DISK_FOLDER_DELETION_RESULT_UNKNOWN';
				$itemOutcomes[] = $preview;
				$stopAfterUnknown = true;
			}
		}

		$response = $this->buildMutationResponse('delete_folders', [], $itemOutcomes);
		$response['deletionType'] = $deletionType;
		$response['irreversible'] = $deletionType === 'permanent';

		return $response;
	}

	public function getTrashContents(
		int $userId,
		int $storageId,
		int $limit,
		int $offset,
	): array
	{
		$storage = $this->loadSupportedStorage($storageId, 'get_trash_contents');
		$rootFolder = $storage->getRootObject();
		if (!$rootFolder || !$rootFolder->canRead($storage->getSecurityContext($userId)))
		{
			throw new McpException("Access denied for storage {$storageId}.");
		}
		$limit = max(1, min(self::MAX_TRASH_SUMMARY_ITEMS, $limit));
		$offset = max(0, $offset);
		$rows = ObjectTable::getList([
			'select' => ['*'],
			'filter' => [
				'=STORAGE_ID' => $storageId,
				'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_ROOT,
			],
			'order' => ['DELETE_TIME' => 'DESC', 'ID' => 'DESC'],
			'limit' => $limit + 1,
			'offset' => $offset,
		])->fetchAll();

		$objects = array_map(
			static fn(array $row): BaseObject => BaseObject::buildFromArray($row),
			array_slice($rows, 0, $limit),
		);
		$readableObjectIds = $this->loadReadableObjectIds($storage->getSecurityContext($userId), $objects);
		$readableObjects = [];
		$limitations = [];
		foreach ($objects as $object)
		{
			if (!isset($readableObjectIds[(int)$object->getId()]))
			{
				$limitations[] = ['code' => 'INACCESSIBLE_TRASH_ITEM_OMITTED'];

				continue;
			}
			$readableObjects[] = $object;
		}
		$items = $this->formatTrashObjects($readableObjects);
		$hasMore = count($rows) > $limit;

		return [
			'storageId' => $storageId,
			'items' => $items,
			'limit' => $limit,
			'offset' => $offset,
			'hasMore' => $hasMore,
			'resultMeta' => $this->buildResultMeta($hasMore, $limitations),
		];
	}

	public function restoreObjectsFromTrash(
		int $userId,
		string $objectType,
		array $objectIds,
		bool $confirmed = false,
	): array
	{
		if (!in_array($objectType, ['file', 'folder'], true))
		{
			throw new McpException("Unsupported trash object type '{$objectType}'.");
		}
		$this->validateItemCount($objectIds, self::MAX_MUTATION_ITEMS, "{$objectType}Ids");
		$objectIds = array_map('intval', $objectIds);
		if (min($objectIds) <= 0 || count($objectIds) !== count(array_unique($objectIds)))
		{
			throw new McpException("{$objectType}Ids must contain unique positive IDs.");
		}

		$preparedItems = [];
		foreach ($objectIds as $index => $objectId)
		{
			$preparedItems[] = $this->prepareTrashRestore($userId, $objectType, $objectId, $index);
		}
		$confirmationObjects = array_values(array_filter(array_column($preparedItems, 'object')));
		if ($this->requiresConfirmation($userId, $confirmationObjects, count($objectIds)) && !$confirmed)
		{
			return $this->buildConfirmationResponse(
				"restore_{$objectType}s_from_trash",
				array_column($preparedItems, 'preview'),
			);
		}

		$resultItems = [];
		$itemOutcomes = [];
		$stopAfterUnknown = false;
		foreach ($preparedItems as $preparedItem)
		{
			$preview = $preparedItem['preview'];
			if ($stopAfterUnknown)
			{
				$preview['status'] = 'not_attempted_after_unknown_result';
				$itemOutcomes[] = $preview;

				continue;
			}
			if ($preview['status'] !== 'ready')
			{
				$preview['status'] = 'failed';
				$preview['errorCode'] = $preparedItem['errorCode'];
				$itemOutcomes[] = $preview;

				continue;
			}
			try
			{
				/** @var BaseObject $object */
				$object = $preparedItem['object'];
				if (!$object->restore($userId))
				{
					$preview['status'] = 'failed';
					$preview['errorCode'] = 'DISK_TRASH_RESTORE_FAILED';
					$itemOutcomes[] = $preview;

					continue;
				}
				$restoredObject = $objectType === 'file'
					? File::loadById($object->getId())
					: Folder::loadById($object->getId())
				;
				$item = $this->formatObject($restoredObject ?? $object);
				$resultItems[] = $item;
				$preview['status'] = 'success';
				$preview['item'] = $item;
				$itemOutcomes[] = $preview;
			}
			catch (\Throwable)
			{
				$preview['status'] = 'unknown_result';
				$preview['errorCode'] = 'DISK_TRASH_RESTORE_RESULT_UNKNOWN';
				$itemOutcomes[] = $preview;
				$stopAfterUnknown = true;
			}
		}

		return $this->buildMutationResponse(
			"restore_{$objectType}s_from_trash",
			$resultItems,
			$itemOutcomes,
		);
	}

	public function emptyTrash(int $userId, int $storageId, bool $confirmed = false): array
	{
		$storage = $this->loadSupportedStorage($storageId, 'empty_trash');
		$rootFolder = $storage->getRootObject();
		$securityContext = $storage->getSecurityContext($userId);
		if (!$rootFolder || !$rootFolder->canRead($securityContext))
		{
			throw new McpException("Access denied for storage {$storageId}.");
		}
		$summary = $this->loadTrashSummary($securityContext, $storageId);
		if (
			$summary['rootObjectsCount'] > self::MAX_TRASH_SUMMARY_ITEMS
			|| $summary['objectsCount'] !== $summary['readableObjectsCount']
		)
		{
			throw new McpException('Trash summary is incomplete; empty_trash was not scheduled.');
		}
		if (!$confirmed)
		{
			return $this->buildConfirmationResponse('empty_trash', [[
				'storageId' => $storageId,
				'uiVisibleRootObjectsCount' => $summary['uiVisibleRootObjectsCount'],
				'uiHiddenRootObjectsCount' => $summary['uiHiddenRootObjectsCount'],
				'uiRetentionDays' => $summary['uiRetentionDays'],
				'rootObjectsCount' => $summary['rootObjectsCount'],
				'objectsCount' => $summary['objectsCount'],
				'estimatedBytes' => $summary['estimatedBytes'],
				'status' => 'ready',
			]]);
		}

		$task = $this->getTrashService()->scheduleEmptyTrash($userId, $storage);

		return $task + [
			'storageId' => $storageId,
			'confirmationRequired' => false,
			'resultMeta' => $this->buildResultMeta(false, [], $task['resultStatus']),
		];
	}

	public function getStorageUsage(
		int $userId,
		string $usageType,
		?int $storageId,
		int $limit,
	): array
	{
		return $this->getStorageUsageService()->getUsage($userId, $usageType, $storageId, $limit);
	}

	public function getFolderAccess(int $userId, ?int $folderId, ?string $folderName, ?int $storageId): array
	{
		$folder = $this->resolveFolder($userId, $folderId, $folderName, $storageId);
		if (is_array($folder))
		{
			return $folder;
		}
		$securityContext = $folder->getStorage()->getSecurityContext($userId);
		if (!$folder->canChangeRights($securityContext))
		{
			throw new McpException("Access denied to read rights for folder {$folder->getId()}.");
		}

		$rightsManager = Driver::getInstance()->getRightsManager();
		$limitations = [];
		$rights = $this->formatEffectiveRights(
			$rightsManager->getAllListNormalizeRights($folder->getRealObject()),
			$rightsManager,
			$limitations,
		);

		return [
			'folder' => $this->formatObject($folder),
			'rights' => $rights,
			'total' => count($rights),
			'hasMore' => false,
			'resultMeta' => $this->buildResultMeta(false, $limitations),
		];
	}

	public function manageFolderAccess(
		int $userId,
		string $action,
		string $accessCode,
		?string $accessLevel,
		?int $folderId,
		?string $folderName,
		?int $storageId,
		bool $confirmed = false,
		array $accessCodes = [],
	): array
	{
		if (!in_array($action, ['grant', 'revoke'], true))
		{
			throw new McpException("Unknown access action '{$action}'.");
		}
		$accessCodes = array_values(array_unique(array_filter(
			array_merge($accessCode !== '' ? [$accessCode] : [], $accessCodes),
			static fn(mixed $code): bool => is_string($code) && $code !== '',
		)));
		if (empty($accessCodes) || count($accessCodes) > self::MAX_LIMIT)
		{
			throw new McpException('Pass between 1 and 50 finite accessCode/accessCodes values.');
		}
		foreach ($accessCodes as $code)
		{
			if (!$this->isManageableSharingAccessCode($code))
			{
				throw new McpException("Invalid accessCode '{$code}'. Use U123, DR7 or SG45_K.");
			}
		}

		$folder = $this->resolveFolder($userId, $folderId, $folderName, $storageId);
		if (is_array($folder))
		{
			return $folder;
		}
		$securityContext = $folder->getStorage()->getSecurityContext($userId);
		if (!$folder->canChangeRights($securityContext))
		{
			throw new McpException("Access denied to change rights for folder {$folder->getId()}.");
		}
		$taskName = null;
		if ($action === 'grant')
		{
			if ($accessLevel === null)
			{
				throw new McpException('accessLevel is required for grant. Ask the user whether to grant read, edit or full access.');
			}
			foreach ($accessCodes as $code)
			{
				if (!$this->canGrantAccessToSubject($userId, $code))
				{
					throw new McpException("Access subject '{$code}' is not available for sharing by the current user.");
				}
			}

			$taskName = $this->mapAccessLevelToTaskName($accessLevel);
		}

		$subjects = $this->describeAccessCodes($accessCodes);
		if (!$confirmed)
		{
			return [
				'folder' => $this->formatObject($folder),
				'action' => $action,
				'accessCode' => $accessCodes[0],
				'accessCodes' => $accessCodes,
				'accessLevel' => $accessLevel,
				'subjects' => $subjects,
				'confirmationRequired' => true,
				'warning' => $action === 'revoke'
					? 'Revoking folder access may disconnect shared folders and terminate active document sessions.'
					: 'Granting folder access changes who can read or modify its contents.',
				'itemOutcomes' => array_map(
					static fn(string $code): array => ['accessCode' => $code, 'status' => 'pending_confirmation'],
					$accessCodes,
				),
				'resultMeta' => $this->buildResultMeta(false, [], 'requires_confirmation'),
			];
		}

		$itemOutcomes = [];
		$terminationPlan = null;
		$terminationScheduled = false;
		foreach ($accessCodes as $code)
		{
			try
			{
				$result = $this->getFolderAccessManager()->manage(
					$folder,
					$securityContext,
					$userId,
					$action,
					$code,
					$taskName,
					$terminationPlan,
					$terminationScheduled,
				);
				$itemOutcomes[] = ['accessCode' => $code, 'status' => 'success', 'changed' => $result['changed']];
			}
			catch (\Throwable)
			{
				$itemOutcomes[] = [
					'accessCode' => $code,
					'status' => 'unknown_result',
					'errorCode' => 'DISK_ACCESS_MUTATION_RESULT_UNKNOWN',
				];

				break;
			}
		}
		$completedCodes = array_column($itemOutcomes, 'accessCode');
		foreach (array_diff($accessCodes, $completedCodes) as $code)
		{
			$itemOutcomes[] = ['accessCode' => $code, 'status' => 'not_attempted_after_unknown_result'];
		}
		$hasUnknownResult = count($itemOutcomes) !== count($accessCodes)
			|| array_filter($itemOutcomes, static fn(array $item): bool => $item['status'] !== 'success');

		return [
			'folder' => $this->formatObject($folder),
			'action' => $action,
			'accessCode' => $accessCodes[0],
			'accessCodes' => $accessCodes,
			'accessLevel' => $accessLevel,
			'success' => !$hasUnknownResult,
			'changed' => !$hasUnknownResult && (bool)array_filter($itemOutcomes, static fn(array $item): bool => $item['changed']),
			'confirmationRequired' => false,
			'itemOutcomes' => $itemOutcomes,
			'resultMeta' => $this->buildResultMeta(false, [], $hasUnknownResult ? 'unknown_result' : 'success'),
		];
	}

	private function getFolderAccessManager(): FolderAccessManager
	{
		return $this->folderAccessManager ??= new FolderAccessManager();
	}

	private function getSupportedStoragePolicy(): SupportedStoragePolicy
	{
		return $this->supportedStoragePolicy ??= new SupportedStoragePolicy();
	}

	private function getTrashService(): TrashService
	{
		return $this->trashService ??= new TrashService();
	}

	private function getStorageUsageService(): StorageUsageService
	{
		return $this->storageUsageService ??= new StorageUsageService();
	}

	private function getExternalLinkAccessService(): ExternalLinkAccessService
	{
		return $this->externalLinkAccessService ??= ServiceLocator::getInstance()->get(ExternalLinkAccessService::class);
	}

	private function getExternalLinkTariffProvider(): ExternalLinkTariffProvider
	{
		return $this->externalLinkTariffProvider ??= ServiceLocator::getInstance()
			->get(ExternalLinkTariffProvider::class)
		;
	}

	/**
	 * With public links switched off on the portal a new link must not be made; an existing one is still returned.
	 *
	 * @throws McpException
	 */
	private function assertPublicLinkExistsWhileDisabledOnPortal(File $file): void
	{
		try
		{
			$externalLink = (new ExternalLinkBitrixOrmRepository())->getForUse($file->getRealObjectId());
		}
		catch (\Throwable)
		{
			$externalLink = null;
		}

		if (!$externalLink)
		{
			throw $this->buildPublicLinksDisabledOnPortalException($file);
		}
	}

	private function buildPublicLinksDisabledOnPortalException(File $file): McpException
	{
		return new McpException(
			"Public links are disabled in the Disk settings of the portal, a public link for file {$file->getId()}"
			. ' cannot be created. Ask the Bitrix24 administrator to allow public links.'
		);
	}

	private function buildPublicLinksDisabledByTariffException(File $file): McpException
	{
		return new McpException(
			"Public links are not available on the current Bitrix24 plan, a public link for file {$file->getId()}"
			. ' cannot be created.'
		);
	}

	private function collectTrashCandidates(int $userId, Storage $storage, int $limit): array
	{
		$rows = ObjectTable::getList([
			'select' => ['*'],
			'filter' => [
				'=STORAGE_ID' => $storage->getId(),
				'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_ROOT,
			],
			'order' => ['TYPE' => 'ASC'],
			'limit' => $limit + 1,
		])->fetchAll();
		usort($rows, static fn(array $left, array $right): int => [
			(int)$left['TYPE'],
			(int)$left['ID'],
		] <=> [
			(int)$right['TYPE'],
			(int)$right['ID'],
		]);
		$items = [];
		$incomplete = count($rows) > $limit;
		$objects = array_map(
			static fn(array $row): BaseObject => BaseObject::buildFromArray($row),
			array_slice($rows, 0, $limit),
		);
		$readableObjectIds = $this->loadReadableObjectIds($storage->getSecurityContext($userId), $objects);
		$folderRealIds = [];
		foreach ($objects as $object)
		{
			if ($object instanceof Folder && isset($readableObjectIds[(int)$object->getId()]))
			{
				$folderRealIds[] = (int)$object->getRealObjectId();
			}
		}
		$folderMetricsById = $this->loadTrashFolderMetrics($folderRealIds);
		foreach ($objects as $object)
		{
			if (!isset($readableObjectIds[(int)$object->getId()]))
			{
				$incomplete = true;

				continue;
			}
			$items[] = [
				'category' => 'trash',
				'reason' => 'The object is currently in the storage trash.',
				'evidence' => [
					'deletedAt' => $this->formatDateTime($object->getDeleteTime()),
					'storageId' => (int)$storage->getId(),
				],
				'estimatedBytes' => $object instanceof Folder
					? ($folderMetricsById[(int)$object->getRealObjectId()]['size'] ?? 0)
					: (int)$object->getSize(),
				'entityType' => $object instanceof Folder ? 'folder' : 'file',
				'entityId' => (int)$object->getId(),
				'confidence' => 'high',
			];
		}

		return ['items' => $items, 'truncated' => $incomplete];
	}

	private function collectOldLargeCandidates(int $userId, Storage $storage, int $limit): array
	{
		$scanLimit = self::CLEANUP_SOURCE_SCAN_LIMIT;
		$oldestAllowedUpdateTime = time() - 90 * 86400;
		$rows = ObjectTable::getList([
			'select' => ['*'],
			'filter' => [
				'=STORAGE_ID' => $storage->getId(),
				'=TYPE' => ObjectTable::TYPE_FILE,
				'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
			],
			'limit' => $scanLimit + 1,
		])->fetchAll();
		$items = [];
		$incomplete = count($rows) > $scanLimit;
		$files = array_map(
			static fn(array $row): BaseObject => BaseObject::buildFromArray($row),
			array_slice($rows, 0, $scanLimit),
		);
		$readableFileIds = $this->loadReadableObjectIds($storage->getSecurityContext($userId), $files);
		foreach ($files as $file)
		{
			$updateTime = $file->getUpdateTime();
			if (!$updateTime || $updateTime->getTimestamp() >= $oldestAllowedUpdateTime)
			{
				continue;
			}
			if ((int)$file->getSize() < 10 * 1024 * 1024)
			{
				continue;
			}
			if (!isset($readableFileIds[(int)$file->getId()]))
			{
				$incomplete = true;

				continue;
			}
			if (count($items) >= $limit)
			{
				$incomplete = true;

				continue;
			}
			$items[] = [
				'category' => 'old_large_copy',
				'reason' => 'The file is large and has not been updated for at least 90 days.',
				'evidence' => [
					'size' => (int)$file->getSize(),
					'updatedAt' => $this->formatDateTime($file->getUpdateTime()),
				],
				'estimatedBytes' => (int)$file->getSize(),
				'entityType' => 'file',
				'entityId' => (int)$file->getId(),
				'confidence' => 'low',
			];
		}

		return ['items' => $items, 'truncated' => $incomplete];
	}

	private function collectVersionCandidates(int $userId, Storage $storage, int $limit): array
	{
		$scanLimit = self::CLEANUP_SOURCE_SCAN_LIMIT;
		$rows = VersionTable::getList([
			'select' => ['ID', 'OBJECT_ID', 'FILE_ID', 'SIZE', 'CREATE_TIME'],
			'filter' => [
				'<CREATE_TIME' => DateTime::createFromTimestamp(time() - 90 * 86400),
			],
			'order' => ['CREATE_TIME' => 'ASC', 'OBJECT_ID' => 'ASC', 'FILE_ID' => 'ASC'],
			'limit' => $scanLimit + 1,
		])->fetchAll();
		$items = [];
		$incomplete = count($rows) > $scanLimit;
		$rows = array_slice($rows, 0, $scanLimit);
		$filesById = [];
		foreach (File::loadBatchById(array_values(array_unique(array_map(
			'intval',
			array_column($rows, 'OBJECT_ID'),
		)))) as $file)
		{
			$filesById[(int)$file->getId()] = $file;
		}
		$filesInStorage = array_filter(
			$filesById,
			static fn(File $file): bool => (int)$file->getStorageId() === (int)$storage->getId(),
		);
		$readableFileIds = $this->loadReadableObjectIds(
			$storage->getSecurityContext($userId),
			array_values($filesInStorage),
		);
		foreach ($rows as $row)
		{
			$file = $filesById[(int)$row['OBJECT_ID']] ?? null;
			if (!$file)
			{
				$incomplete = true;

				continue;
			}
			if ((int)$file->getStorageId() !== (int)$storage->getId())
			{
				continue;
			}
			if (!isset($readableFileIds[(int)$file->getId()]))
			{
				$incomplete = true;

				continue;
			}
			if (count($items) >= $limit)
			{
				$incomplete = true;

				continue;
			}
			$items[] = [
				'category' => 'unnecessary_version',
				'reason' => 'The stored file version is older than 90 days.',
				'evidence' => [
					'fileId' => (int)$file->getId(),
					'createdAt' => $this->formatDateTime($row['CREATE_TIME']),
				],
				'estimatedBytes' => (int)$row['SIZE'],
				'entityType' => 'version',
				'entityId' => (int)$row['ID'],
				'confidence' => 'low',
			];
		}

		return ['items' => $items, 'truncated' => $incomplete];
	}

	private function collectDuplicateCandidates(int $userId, Storage $storage, int $limit): array
	{
		$scanLimit = min(self::CLEANUP_SOURCE_SCAN_LIMIT, max($limit * 3, $limit + 1));
		$rows = ObjectTable::getList([
			'select' => ['*'],
			'filter' => [
				'=STORAGE_ID' => $storage->getId(),
				'=TYPE' => ObjectTable::TYPE_FILE,
				'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
			],
			'limit' => $scanLimit + 1,
		])->fetchAll();
		usort($rows, static fn(array $left, array $right): int => (int)$left['ID'] <=> (int)$right['ID']);
		$items = [];
		$seen = [];
		$incomplete = count($rows) > $scanLimit;
		$files = array_map(
			static fn(array $row): BaseObject => BaseObject::buildFromArray($row),
			array_slice($rows, 0, $scanLimit),
		);
		$readableFileIds = $this->loadReadableObjectIds($storage->getSecurityContext($userId), $files);
		foreach ($files as $file)
		{
			if ((int)$file->getSize() <= 0)
			{
				continue;
			}
			if (!isset($readableFileIds[(int)$file->getId()]))
			{
				$incomplete = true;

				continue;
			}
			$key = mb_strtolower($file->getName()) . ':' . $file->getSize();
			if (!isset($seen[$key]))
			{
				$seen[$key] = (int)$file->getId();

				continue;
			}
			if (count($items) >= $limit)
			{
				$incomplete = true;

				continue;
			}
			$items[] = [
				'category' => 'duplicate',
				'reason' => 'Another readable file has the same name and size.',
				'evidence' => [
					'matchingFileId' => $seen[$key],
					'name' => $file->getName(),
					'size' => (int)$file->getSize(),
				],
				'estimatedBytes' => (int)$file->getSize(),
				'entityType' => 'file',
				'entityId' => (int)$file->getId(),
				'confidence' => 'low',
			];
		}

		return ['items' => $items, 'truncated' => $incomplete];
	}

	private function deduplicateCleanupCandidates(array $candidates): array
	{
		$result = [];
		foreach ($candidates as $candidate)
		{
			$key = $candidate['entityType'] . ':' . $candidate['entityId'];
			$result[$key] ??= $candidate;
		}

		return array_values($result);
	}

	private function sortCleanupCandidates(array &$candidates, string $strategy): void
	{
		$confidence = ['low' => 1, 'medium' => 2, 'high' => 3];
		usort($candidates, static function (array $left, array $right) use ($strategy, $confidence): int {
			$sizeComparison = $right['estimatedBytes'] <=> $left['estimatedBytes'];
			$confidenceComparison = $confidence[$right['confidence']] <=> $confidence[$left['confidence']];
			$leftDate = $left['evidence']['deletedAt']
				?? $left['evidence']['updatedAt']
				?? $left['evidence']['createdAt']
				?? '9999-12-31T23:59:59+00:00';
			$rightDate = $right['evidence']['deletedAt']
				?? $right['evidence']['updatedAt']
				?? $right['evidence']['createdAt']
				?? '9999-12-31T23:59:59+00:00';
			$comparisons = match ($strategy)
			{
				'largest_first' => [$sizeComparison, $confidenceComparison],
				'oldest_first' => [$leftDate <=> $rightDate, $confidenceComparison, $sizeComparison],
				default => [$confidenceComparison, $sizeComparison],
			};
			foreach ($comparisons as $comparison)
			{
				if ($comparison !== 0)
				{
					return $comparison;
				}
			}

			return [$left['entityType'], $left['entityId']] <=> [$right['entityType'], $right['entityId']];
		});
	}

	private function prepareFolderDeletion(
		?Folder $folder,
		int $folderId,
		string $deletionType,
		int $index,
	): array
	{
		$preview = [
			'index' => $index,
			'folderId' => $folderId,
			'deletionType' => $deletionType,
			'irreversible' => $deletionType === 'permanent',
			'status' => 'ready',
		];
		$errorCode = null;
		if (!$folder)
		{
			$errorCode = 'NOT_FOUND';
		}
		elseif (!$this->getSupportedStoragePolicy()->supports($folder->getStorage()))
		{
			$errorCode = 'UNSUPPORTED_STORAGE';
		}
		elseif ($folder->getStorage()->getRootObjectId() === $folder->getId())
		{
			$errorCode = 'ROOT_FOLDER_DELETION_FORBIDDEN';
		}
		else
		{
			$treeRows = ObjectPathTable::getList([
				'select' => ['OBJECT_ID'],
				'filter' => ['=PARENT_ID' => $folder->getRealObjectId()],
				'order' => ['DEPTH_LEVEL' => 'DESC', 'OBJECT_ID' => 'ASC'],
				'limit' => self::MAX_FOLDER_DELETE_OBJECTS + 1,
			])->fetchAll();
			$preview['treeObjectsCount'] = count($treeRows);
			if (count($treeRows) > self::MAX_FOLDER_DELETE_OBJECTS)
			{
				$errorCode = 'LIMIT_EXCEEDED';
			}
		}
		if ($errorCode !== null)
		{
			$preview['status'] = mb_strtolower($errorCode);
		}

		return [
			'folder' => $folder,
			'treeObjectIds' => isset($treeRows) ? array_map('intval', array_column($treeRows, 'OBJECT_ID')) : [],
			'preview' => $preview,
			'errorCode' => $errorCode,
		];
	}

	private function preloadOperationsForObjects(array $objects, SecurityContext $securityContext): void
	{
		$objectIds = array_values(array_unique(array_map(
			static fn(BaseObject $object): int => (int)$object->getId(),
			$objects,
		)));
		if ($securityContext instanceof DiskSecurityContext)
		{
			$securityContext->preloadOperationsForObjects($objects);

			return;
		}

		$objectIdsByParent = [];
		foreach ($objects as $object)
		{
			$parentId = (int)$object->getParentId();
			if ($parentId > 0)
			{
				$objectIdsByParent[$parentId][] = (int)$object->getId();
			}
		}
		foreach ($objectIdsByParent as $parentId => $objectIds)
		{
			$securityContext->preloadOperationsForSpecifiedObjects($parentId, $objectIds);
		}
	}

	private function preloadFolderDeletionOperations(array $objectsById, int $userId): array
	{
		$securityContextsByStorageId = [];
		$objectsBySecurityContext = [];
		$diskSecurityContext = null;
		foreach ($objectsById as $object)
		{
			$storageId = (int)$object->getStorageId();
			if (!isset($securityContextsByStorageId[$storageId]))
			{
				$securityContext = $object->getStorage()->getSecurityContext($userId);
				if ($securityContext instanceof DiskSecurityContext)
				{
					$diskSecurityContext ??= $securityContext;
					$securityContext = $diskSecurityContext;
				}
				$securityContextsByStorageId[$storageId] = $securityContext;
			}

			$securityContext = $securityContextsByStorageId[$storageId];
			$securityContextId = spl_object_id($securityContext);
			$objectsBySecurityContext[$securityContextId]['context'] = $securityContext;
			$objectsBySecurityContext[$securityContextId]['objects'][] = $object;
		}

		foreach ($objectsBySecurityContext as $contextData)
		{
			$this->preloadOperationsForObjects($contextData['objects'], $contextData['context']);
		}

		return $securityContextsByStorageId;
	}

	private function loadReadableObjectIds(SecurityContext $securityContext, array $objects): array
	{
		$objectIds = array_values(array_unique(array_map(
			static fn(BaseObject $object): int => (int)$object->getId(),
			$objects,
		)));
		if (!$objectIds)
		{
			return [];
		}

		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			$securityContext,
			[
				'select' => ['ID'],
				'filter' => ['@ID' => $objectIds],
			],
			['ID', 'CREATED_BY'],
		);
		$readableObjectIds = [];
		foreach (ObjectTable::getList($parameters)->fetchAll() as $row)
		{
			$readableObjectIds[(int)$row['ID']] = true;
		}

		return $readableObjectIds;
	}

	private function validateFolderDeletionTree(
		array &$preparedItem,
		array $objectsById,
		int $userId,
		string $deletionType,
		SecurityContext $securityContext,
	): void
	{
		/** @var Folder $folder */
		$folder = $preparedItem['folder'];
		$objects = [];
		foreach ($preparedItem['treeObjectIds'] as $objectId)
		{
			if (!isset($objectsById[$objectId]))
			{
				$preparedItem['errorCode'] = 'TREE_CHANGED';
				$preparedItem['preview']['status'] = 'tree_changed';

				return;
			}
			$objects[] = $objectsById[$objectId];
		}

		$restriction = new DeletionRestriction();
		foreach ($objects as $object)
		{
			$canDelete = $deletionType === 'permanent'
				? $object->canDelete($securityContext)
				: $object->canMarkDeleted($securityContext)
			;
			if (!$canDelete)
			{
				$preparedItem['errorCode'] = 'ACCESS_DENIED';
				$preparedItem['preview']['status'] = 'access_denied';

				return;
			}
			if ($object instanceof Folder && $restriction->isRestricted($object, $userId))
			{
				$preparedItem['errorCode'] = 'DELETION_RESTRICTED';
				$preparedItem['preview']['status'] = 'deletion_restricted';

				return;
			}
		}
	}

	private function buildFolderDeletionFailure(string $deletionType, array $preparedItems): array
	{
		$outcomes = [];
		foreach ($preparedItems as $preparedItem)
		{
			$outcome = $preparedItem['preview'];
			if ($preparedItem['errorCode'] === null)
			{
				$outcome['status'] = 'not_attempted_precheck_failed';
			}
			else
			{
				$outcome['status'] = 'failed';
				$outcome['errorCode'] = $preparedItem['errorCode'];
			}
			$outcomes[] = $outcome;
		}
		$response = $this->buildMutationResponse('delete_folders', [], $outcomes);
		$response['deletionType'] = $deletionType;
		$response['irreversible'] = $deletionType === 'permanent';

		return $response;
	}

	private function formatTrashObject(BaseObject $object): array
	{
		return $this->formatTrashObjects([$object])[0];
	}

	private function formatTrashObjects(array $objects): array
	{
		if (!$objects)
		{
			return [];
		}

		$parentIds = array_values(array_unique(array_filter(array_map(
			static fn(BaseObject $object): int => (int)$object->getParentId(),
			$objects,
		))));
		$parentsById = [];
		if ($parentIds)
		{
			foreach (ObjectTable::getList([
				'select' => ['ID', 'NAME'],
				'filter' => ['@ID' => $parentIds],
			])->fetchAll() as $parent)
			{
				$parentsById[(int)$parent['ID']] = $parent;
			}
		}

		$folderRealIds = [];
		foreach ($objects as $object)
		{
			if ($object instanceof Folder)
			{
				$folderRealIds[] = (int)$object->getRealObjectId();
			}
		}
		$folderMetricsById = $this->loadTrashFolderMetrics($folderRealIds);

		$items = [];
		foreach ($objects as $object)
		{
			$parent = $parentsById[(int)$object->getParentId()] ?? null;
			$folderMetrics = $object instanceof Folder
				? ($folderMetricsById[(int)$object->getRealObjectId()] ?? ['objectsCount' => 0, 'size' => 0])
				: null
			;
			$items[] = [
				'id' => (int)$object->getId(),
				'type' => $object instanceof Folder ? 'folder' : 'file',
				'name' => (string)$object->getName(),
				'storageId' => (int)$object->getStorageId(),
				'originalParent' => $parent
					? ['id' => (int)$parent['ID'], 'name' => (string)$parent['NAME']]
					: null,
				'deletedAt' => $this->formatDateTime($object->getDeleteTime()),
				'size' => $folderMetrics === null ? (int)$object->getSize() : $folderMetrics['size'],
				'objectsCount' => $folderMetrics === null ? 1 : $folderMetrics['objectsCount'],
			];
		}

		return $items;
	}

	private function loadTrashFolderMetrics(
		array $folderRealIds,
		?SecurityContext $securityContext = null,
	): array
	{
		$folderRealIds = array_values(array_unique(array_filter(array_map('intval', $folderRealIds))));
		$folderMetricsById = [];
		foreach (array_chunk($folderRealIds, self::TRASH_FOLDER_METRICS_CHUNK_SIZE) as $folderRealIdChunk)
		{
			$select = ['PARENT_ID', 'OBJECTS_COUNT', 'FOLDER_SIZE'];
			$runtime = [
				new ReferenceField(
					'PATH_OBJECT',
					ObjectTable::class,
					['=this.OBJECT_ID' => 'ref.ID'],
					['join_type' => 'INNER'],
				),
				new ExpressionField('OBJECTS_COUNT', 'COUNT(%s)', 'OBJECT_ID'),
				new ExpressionField(
					'FOLDER_SIZE',
					'COALESCE(SUM(CASE WHEN %s = ' . ObjectTable::TYPE_FILE
						. ' THEN %s ELSE 0 END), 0)',
					['PATH_OBJECT.TYPE', 'PATH_OBJECT.SIZE'],
				),
			];
			if ($securityContext !== null)
			{
				$rightsExpression = $securityContext->getSqlExpressionForList('%1$s', '%2$s');
				$select[] = 'READABLE_OBJECTS_COUNT';
				$runtime[] = new ExpressionField(
					'READABLE_OBJECTS_COUNT',
					"COALESCE(SUM(CASE WHEN {$rightsExpression} THEN 1 ELSE 0 END), 0)",
					['PATH_OBJECT.ID', 'PATH_OBJECT.CREATED_BY'],
				);
			}
			$rows = ObjectPathTable::getList([
				'select' => $select,
				'filter' => ['@PARENT_ID' => $folderRealIdChunk],
				'group' => ['PARENT_ID'],
				'runtime' => $runtime,
			])->fetchAll();
			foreach ($rows as $row)
			{
				$folderMetricsById[(int)$row['PARENT_ID']] = [
					'objectsCount' => (int)$row['OBJECTS_COUNT'],
					'size' => (int)$row['FOLDER_SIZE'],
					'readableObjectsCount' => $securityContext === null
						? null
						: (int)$row['READABLE_OBJECTS_COUNT'],
				];
			}
		}

		return $folderMetricsById;
	}

	private function loadTrashSummary(SecurityContext $securityContext, int $storageId): array
	{
		$rightsExpression = $securityContext->getSqlExpressionForList('%1$s', '%2$s');
		$parameters = [
			'select' => ['OBJECTS_COUNT', 'ESTIMATED_BYTES', 'READABLE_OBJECTS_COUNT'],
			'filter' => [
				'=STORAGE_ID' => $storageId,
				'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_ROOT,
			],
			'runtime' => [
				new ExpressionField('OBJECTS_COUNT', 'COUNT(%s)', 'ID'),
				new ExpressionField(
					'ESTIMATED_BYTES',
					'COALESCE(SUM(CASE WHEN %s = ' . ObjectTable::TYPE_FILE
						. ' THEN %s ELSE 0 END), 0)',
					['TYPE', 'SIZE'],
				),
				new ExpressionField(
					'READABLE_OBJECTS_COUNT',
					"COALESCE(SUM(CASE WHEN {$rightsExpression} THEN 1 ELSE 0 END), 0)",
					['ID', 'CREATED_BY'],
				),
			],
		];
		$summary = ObjectTable::getRow($parameters) ?: [];
		$rootObjectsCount = (int)($summary['OBJECTS_COUNT'] ?? 0);
		$objectsCount = $rootObjectsCount;
		$readableObjectsCount = (int)($summary['READABLE_OBJECTS_COUNT'] ?? 0);
		$estimatedBytes = (int)($summary['ESTIMATED_BYTES'] ?? 0);
		$trashCanTtl = Configuration::getTrashCanTtl();
		$uiVisibleRootObjectsCount = $rootObjectsCount;
		if ($trashCanTtl !== -1)
		{
			$uiVisibleRootObjectsCount = ObjectTable::getCount([
				'=STORAGE_ID' => $storageId,
				'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_ROOT,
				'>DELETE_TIME' => DateTime::createFromTimestamp(time() - $trashCanTtl * 86400),
			]);
		}
		if (
			$rootObjectsCount <= self::MAX_TRASH_SUMMARY_ITEMS
			&& $rootObjectsCount === $readableObjectsCount
		)
		{
			$folders = ObjectTable::getList([
				'select' => ['ID', 'REAL_OBJECT_ID'],
				'filter' => [
					'=STORAGE_ID' => $storageId,
					'=TYPE' => ObjectTable::TYPE_FOLDER,
					'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_ROOT,
				],
				'limit' => self::MAX_TRASH_SUMMARY_ITEMS,
			])->fetchAll();
			$folderRealIds = array_map(
				static fn(array $folder): int => (int)($folder['REAL_OBJECT_ID'] ?: $folder['ID']),
				$folders,
			);
			$folderMetricsById = $this->loadTrashFolderMetrics($folderRealIds, $securityContext);
			foreach ($folderRealIds as $folderRealId)
			{
				$folderMetrics = $folderMetricsById[$folderRealId] ?? null;
				if ($folderMetrics === null)
				{
					continue;
				}

				$objectsCount += max(0, $folderMetrics['objectsCount'] - 1);
				$readableObjectsCount += max(0, $folderMetrics['readableObjectsCount'] - 1);
				$estimatedBytes += $folderMetrics['size'];
			}
		}

		return [
			'uiVisibleRootObjectsCount' => $uiVisibleRootObjectsCount,
			'uiHiddenRootObjectsCount' => max(0, $rootObjectsCount - $uiVisibleRootObjectsCount),
			'uiRetentionDays' => $trashCanTtl === -1 ? null : $trashCanTtl,
			'rootObjectsCount' => $rootObjectsCount,
			'objectsCount' => $objectsCount,
			'estimatedBytes' => $estimatedBytes,
			'readableObjectsCount' => $readableObjectsCount,
		];
	}

	private function prepareTrashRestore(int $userId, string $objectType, int $objectId, int $index): array
	{
		$object = $objectType === 'file' ? File::loadById($objectId) : Folder::loadById($objectId);
		$preview = [
			'index' => $index,
			"{$objectType}Id" => $objectId,
			'status' => 'ready',
		];
		$errorCode = null;
		if (!$object)
		{
			$errorCode = 'NOT_FOUND';
		}
		elseif (!$object->canRestore($object->getStorage()->getSecurityContext($userId)))
		{
			$errorCode = 'ACCESS_DENIED';
		}
		elseif ($object->getDeletedType() === ObjectTable::DELETED_TYPE_NONE)
		{
			$errorCode = 'NOT_IN_TRASH';
		}
		elseif (!$this->getSupportedStoragePolicy()->supports($object->getStorage()))
		{
			$errorCode = 'UNSUPPORTED_STORAGE';
		}
		elseif (!$object->getParent())
		{
			$errorCode = 'PARENT_NOT_FOUND';
		}
		elseif ($this->hasNameConflict($object->getParent(), $object->getName(), $objectType))
		{
			$errorCode = 'NAME_CONFLICT';
		}
		if ($object && $errorCode !== 'ACCESS_DENIED')
		{
			$preview['object'] = $this->formatTrashObject($object);
		}
		if ($errorCode !== null)
		{
			$preview['status'] = mb_strtolower($errorCode);
		}

		return ['object' => $object, 'preview' => $preview, 'errorCode' => $errorCode];
	}

	private function resolveFileVersionByDate(File $file, string $versionDate): Version
	{
		$versionDate = trim($versionDate);
		if ($versionDate === '')
		{
			throw new McpException('versionDate must be a non-empty ISO 8601 date.');
		}
		try
		{
			$timestamp = (new \DateTimeImmutable($versionDate))->getTimestamp();
		}
		catch (\Throwable)
		{
			throw new McpException('versionDate must be a valid ISO 8601 date.');
		}

		$rows = VersionTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=OBJECT_ID' => $file->getId(),
				'=CREATE_TIME' => DateTime::createFromTimestamp($timestamp),
			],
			'limit' => 2,
		])->fetchAll();
		if (count($rows) !== 1)
		{
			throw new McpException(
				count($rows) === 0
					? 'No file version matches versionDate.'
					: 'Several file versions match versionDate; pass versionId.',
			);
		}

		$version = $file->getVersion((int)$rows[0]['ID']);
		if (!$version)
		{
			throw new McpException('The resolved version is no longer available.');
		}

		return $version;
	}

	private function formatVersionModel(Version $version): array
	{
		return [
			'id' => (int)$version->getId(),
			'fileId' => (int)$version->getObjectId(),
			'name' => (string)$version->getName(),
			'size' => (int)$version->getSize(),
			'createTime' => $this->formatDateTime($version->getCreateTime()),
			'createdBy' => (int)$version->getCreatedBy(),
		];
	}

	private function buildPublicLinkResponse(File $file, ExternalLink $externalLink, bool $created): array
	{
		$link = Driver::getInstance()->getUrlManager()->getCopyablePublicExternalLinkForConsumer(
			$externalLink,
			UrlManager::PUBLIC_LINK_CONSUMER_UNMARKED_COPY,
			$file,
		);

		return [
			'file' => $this->formatObject($file),
			'publicLink' => [
				'id' => (int)$externalLink->getId(),
				'fileId' => (int)$file->getId(),
				'link' => $link,
			],
			'created' => $created,
			'success' => true,
			'confirmationRequired' => false,
			'itemOutcomes' => [[
				'fileId' => (int)$file->getId(),
				'publicLinkId' => (int)$externalLink->getId(),
				'status' => 'success',
				'created' => $created,
			]],
			'resultMeta' => $this->buildResultMeta(false),
		];
	}

	private function buildUnknownPublicLinkResponse(int $fileId): array
	{
		return [
			'fileId' => $fileId,
			'publicLink' => null,
			'success' => false,
			'confirmationRequired' => false,
			'retrySafe' => false,
			'itemOutcomes' => [[
				'fileId' => $fileId,
				'status' => 'unknown_result',
				'errorCode' => 'PUBLIC_LINK_STATE_UNKNOWN',
			]],
			'resultMeta' => $this->buildResultMeta(false, [], 'unknown_result'),
		];
	}

	private function prepareUploadFiles(array $files): array
	{
		$preparedFiles = [];
		$totalSize = 0;
		$maxEncodedLength = (int)ceil(self::MAX_UPLOAD_ITEM_SIZE / 3) * 4;
		foreach ($files as $index => $file)
		{
			if (!is_array($file))
			{
				throw new McpException("files[{$index}] must be an object.");
			}

			$name = trim((string)($file['name'] ?? ''));
			$contentType = trim((string)($file['contentType'] ?? ''));
			$contentBase64 = $file['contentBase64'] ?? null;
			if ($name === '' || mb_strlen($name) > 255)
			{
				throw new McpException("files[{$index}].name must contain between 1 and 255 characters.");
			}
			if ($contentType === '' || strlen($contentType) > 255)
			{
				throw new McpException("files[{$index}].contentType must contain between 1 and 255 characters.");
			}
			if (!is_string($contentBase64) || strlen($contentBase64) > $maxEncodedLength)
			{
				throw new McpException("files[{$index}].contentBase64 exceeds the allowed size.");
			}

			$content = base64_decode($contentBase64, true);
			if ($content === false || base64_encode($content) !== $contentBase64)
			{
				throw new McpException("files[{$index}].contentBase64 must be canonical base64.");
			}

			$size = strlen($content);
			if ($size > self::MAX_UPLOAD_ITEM_SIZE)
			{
				throw new McpException("files[{$index}] exceeds the per-file size limit.");
			}
			$totalSize += $size;
			if ($totalSize > self::MAX_UPLOAD_TOTAL_SIZE)
			{
				throw new McpException('files exceed the total decoded size limit.');
			}

			$preparedFiles[] = [
				'name' => $name,
				'contentType' => $contentType,
				'content' => $content,
			];
		}

		return $preparedFiles;
	}

	private function resolveCreateFoldersTarget(int $userId, ?int $folderId, ?int $storageId): Folder
	{
		if ($folderId !== null || $storageId !== null)
		{
			return $this->resolveWriteTarget($folderId, $storageId, 'create_folders');
		}

		$storage = Driver::getInstance()->getStorageByUserId($userId);
		if (!$storage)
		{
			throw new McpException('Personal Disk storage of the current user is not available.');
		}
		$this->ensureSupportedStorage($storage, 'create_folders');

		$rootFolder = $storage->getRootObject();
		if (!$rootFolder)
		{
			throw new McpException('Personal Disk storage of the current user has no root folder.');
		}

		return $rootFolder;
	}

	private function normalizeNames(array $names): array
	{
		$result = [];
		foreach ($names as $index => $name)
		{
			if (!is_string($name))
			{
				throw new McpException("names[{$index}] must be a string.");
			}

			$name = trim($name);
			if ($name === '' || mb_strlen($name) > 255)
			{
				throw new McpException("names[{$index}] must contain between 1 and 255 characters.");
			}

			$result[] = $name;
		}

		return $result;
	}

	private function resolveWriteTarget(?int $folderId, ?int $storageId, string $toolName): Folder
	{
		if (($folderId === null) === ($storageId === null))
		{
			throw new McpException("{$toolName} requires exactly one folderId or storageId destination.");
		}
		if ($folderId !== null)
		{
			if ($folderId <= 0)
			{
				throw new McpException('folderId must be greater than zero.');
			}

			$folder = Folder::loadById($folderId);
			if (!$folder)
			{
				throw new McpException("Folder {$folderId} not found.");
			}
			$this->ensureSupportedStorage($folder->getStorage(), $toolName);

			return $folder;
		}

		if ($storageId <= 0)
		{
			throw new McpException('storageId must be greater than zero.');
		}

		$storage = $this->loadSupportedStorage($storageId, $toolName);
		$rootFolder = $storage->getRootObject();
		if (!$rootFolder)
		{
			throw new McpException("Storage {$storageId} has no root folder.");
		}

		return $rootFolder;
	}

	private function ensureCanAdd(int $userId, Folder $targetFolder, string $operation): void
	{
		$securityContext = $targetFolder->getStorage()->getSecurityContext($userId);
		if (!$targetFolder->canAdd($securityContext))
		{
			$folderId = (int)$targetFolder->getId();

			throw new McpException("Access denied to {$operation} in folder {$folderId}.");
		}
	}

	private function requiresConfirmation(int $userId, array $objects, int $itemCount): bool
	{
		if ($itemCount > 1)
		{
			return true;
		}

		foreach ($objects as $object)
		{
			$storage = $object instanceof Storage ? $object : $object->getStorage();
			if (
				!$storage->getProxyType() instanceof ProxyType\User
				|| (int)$storage->getEntityId() !== $userId
			)
			{
				return true;
			}
		}

		return false;
	}

	private function buildConfirmationResponse(
		string $operation,
		array $targets,
		?Folder $destination = null,
	): array
	{
		$destinationItem = $destination ? $this->formatObject($destination) : null;

		return [
			'operation' => $operation,
			'items' => [],
			'confirmationRequired' => true,
			'preview' => [
				'operation' => $operation,
				'targets' => $targets,
				'destination' => $destinationItem,
			],
			'itemOutcomes' => array_map(
				static function (array $target): array {
					$target['status'] = $target['status'] === 'ready'
						? 'pending_confirmation'
						: $target['status'];

					return $target;
				},
				$targets,
			),
			'resultMeta' => $this->buildResultMeta(false, [], 'requires_confirmation'),
		];
	}

	private function buildMutationResponse(
		string $operation,
		array $items,
		array $itemOutcomes,
		?Folder $destination = null,
	): array
	{
		$status = $this->detectMutationStatus($itemOutcomes);

		return [
			'operation' => $operation,
			'items' => $items,
			'success' => $status === 'success',
			'confirmationRequired' => false,
			'destination' => $destination ? $this->formatObject($destination) : null,
			'itemOutcomes' => $itemOutcomes,
			'resultMeta' => $this->buildResultMeta(false, [], $status),
		];
	}

	private function detectMutationStatus(array $itemOutcomes): string
	{
		$statuses = array_column($itemOutcomes, 'status');
		if (in_array('unknown_result', $statuses, true))
		{
			return 'unknown_result';
		}

		$successCount = count(array_filter($statuses, static fn(string $status): bool => $status === 'success'));
		if ($successCount === count($statuses))
		{
			return 'success';
		}

		return $successCount > 0 ? 'partial' : 'failed';
	}

	private function buildCreationOutcome(array $preparedItem): array
	{
		return [
			'index' => $preparedItem['index'],
			'name' => $preparedItem['name'],
			'status' => 'failed',
			'errorCode' => $preparedItem['status'] === 'name_conflict'
				? 'NAME_CONFLICT'
				: 'DISK_OPERATION_FAILED',
		];
	}

	private function buildFailedCreationOutcome(int $index, string $name): array
	{
		return [
			'index' => $index,
			'name' => $name,
			'status' => 'failed',
			'errorCode' => 'DISK_OPERATION_FAILED',
		];
	}

	private function writeTemporaryUpload(string $content): string
	{
		$tempFile = \CTempFile::GetFileName('/disk-mcp-upload');
		\Bitrix\Main\IO\Directory::createDirectory(dirname($tempFile));
		$writtenBytes = file_put_contents($tempFile, $content, LOCK_EX);
		if ($writtenBytes !== strlen($content))
		{
			$this->deleteTemporaryUpload($tempFile);

			throw new McpException('Could not prepare temporary upload file.');
		}

		return $tempFile;
	}

	private function deleteTemporaryUpload(?string $tempFile): void
	{
		if ($tempFile !== null && is_file($tempFile))
		{
			unlink($tempFile);
		}
	}

	private function validateItemCount(array $items, int $maximum, string $fieldName): void
	{
		if (empty($items) || count($items) > $maximum)
		{
			throw new McpException("{$fieldName} must contain between 1 and {$maximum} items.");
		}
	}

	private function hasNameConflict(
		Folder $targetFolder,
		string $name,
		string $objectType,
		?int $excludedObjectId = null,
	): bool
	{
		$filter = [
			'=PARENT_ID' => $targetFolder->getRealObjectId(),
			'=TYPE' => $objectType === 'folder' ? ObjectTable::TYPE_FOLDER : ObjectTable::TYPE_FILE,
			'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
		];
		if ($excludedObjectId !== null)
		{
			$filter['!=ID'] = $excludedObjectId;
		}

		$sameNamed = ObjectNameKey::findSameName(
			[
				'select' => ['ID', 'NAME'],
				'filter' => $filter,
			],
			$name,
		);

		return $sameNamed !== null;
	}

	private function organizeObjects(
		int $userId,
		string $operation,
		string $objectType,
		array $items,
		?Folder $targetFolder,
		bool $confirmed,
	): array
	{
		if (!in_array($operation, ['rename', 'move', 'copy'], true))
		{
			throw new McpException("Unsupported organization operation '{$operation}'.");
		}
		if (!in_array($objectType, ['file', 'folder'], true))
		{
			throw new McpException("Unsupported object type '{$objectType}'.");
		}
		$this->validateItemCount($items, self::MAX_MUTATION_ITEMS, 'items');
		if ($operation !== 'rename' && !$targetFolder)
		{
			throw new McpException("{$operation} requires a destination.");
		}
		if ($targetFolder)
		{
			$this->ensureCanAdd($userId, $targetFolder, "{$operation} objects");
		}

		$resolvedItems = $this->prepareOrganizationItems(
			$userId,
			$operation,
			$objectType,
			$items,
			$targetFolder,
		);
		$objects = array_column($resolvedItems, 'object');
		$confirmationObjects = array_values(array_filter($objects));
		if ($targetFolder)
		{
			$confirmationObjects[] = $targetFolder;
		}
		$previewTargets = array_column($resolvedItems, 'preview');
		if ($this->requiresConfirmation($userId, $confirmationObjects, count($items)) && !$confirmed)
		{
			return $this->buildConfirmationResponse(
				"{$operation}_{$objectType}s",
				$previewTargets,
				$targetFolder,
			);
		}

		$resultItems = [];
		$itemOutcomes = [];
		$stopAfterUnknownResult = false;
		foreach ($resolvedItems as $resolvedItem)
		{
			$preview = $resolvedItem['preview'];
			if ($stopAfterUnknownResult)
			{
				$preview['status'] = 'not_attempted_after_unknown_result';
				$itemOutcomes[] = $preview;

				continue;
			}
			if ($preview['status'] !== 'ready')
			{
				$preview['status'] = 'failed';
				$preview['errorCode'] = $resolvedItem['errorCode'];
				$itemOutcomes[] = $preview;

				continue;
			}

			/** @var BaseObject $object */
			$object = $resolvedItem['object'];
			try
			{
				$result = match ($operation)
				{
					'rename' => $object->rename($resolvedItem['newName'], false) ? $object : null,
					'move' => $object->moveTo($targetFolder, $userId, false),
					'copy' => $object->copyTo($targetFolder, $userId, false),
				};
				if (!$result)
				{
					$preview['status'] = 'failed';
					$preview['errorCode'] = 'DISK_OPERATION_FAILED';
					$itemOutcomes[] = $preview;

					continue;
				}

				$resultItem = $this->formatObject($result);
				$resultItems[] = $resultItem;
				if (
					$operation === 'copy'
					&& $object instanceof Folder
					&& (!($result instanceof Folder) || !$this->isFolderCopyComplete($object, $result))
				)
				{
					$preview['status'] = 'unknown_result';
					$preview['errorCode'] = 'DISK_FOLDER_COPY_INCOMPLETE';
					$preview['item'] = $resultItem;
					$itemOutcomes[] = $preview;
					$stopAfterUnknownResult = true;

					continue;
				}
				$preview['status'] = 'success';
				$preview['item'] = $resultItem;
				$itemOutcomes[] = $preview;
			}
			catch (\Throwable)
			{
				$preview['status'] = 'unknown_result';
				$preview['errorCode'] = 'DISK_ORGANIZATION_RESULT_UNKNOWN';
				$itemOutcomes[] = $preview;
				$stopAfterUnknownResult = true;
			}
		}

		return $this->buildMutationResponse(
			"{$operation}_{$objectType}s",
			$resultItems,
			$itemOutcomes,
			$targetFolder,
		);
	}

	private function prepareOrganizationItems(
		int $userId,
		string $operation,
		string $objectType,
		array $items,
		?Folder $targetFolder,
	): array
	{
		$seenIds = [];
		$result = [];
		foreach ($items as $index => $item)
		{
			if (!is_array($item))
			{
				throw new McpException("items[{$index}] must be an object.");
			}
			$objectId = (int)($item['id'] ?? 0);
			if ($objectId <= 0 || isset($seenIds[$objectId]))
			{
				throw new McpException("items[{$index}].id must be a unique positive object ID.");
			}
			$seenIds[$objectId] = true;

			$object = $objectType === 'file' ? File::loadById($objectId) : Folder::loadById($objectId);
			$preview = [
				'index' => $index,
				"{$objectType}Id" => $objectId,
				'status' => 'ready',
			];
			$errorCode = null;
			$newName = null;
			if (!$object)
			{
				$errorCode = 'NOT_FOUND';
			}
			elseif (!$this->getSupportedStoragePolicy()->supports($object->getStorage()))
			{
				$errorCode = 'UNSUPPORTED_STORAGE';
			}
			else
			{
				$securityContext = $object->getStorage()->getSecurityContext($userId);
				if ($operation === 'rename')
				{
					$newName = trim((string)($item['newName'] ?? ''));
					if ($newName === '' || mb_strlen($newName) > 255)
					{
						throw new McpException("items[{$index}].newName must contain between 1 and 255 characters.");
					}
					$parent = $object->getParent();
					if (!$parent || !$object->canRename($securityContext))
					{
						$errorCode = 'ACCESS_DENIED';
					}
					elseif ($this->hasNameConflict($parent, $newName, $objectType, $objectId))
					{
						$errorCode = 'NAME_CONFLICT';
					}
				}
				elseif (!$object->canRead($securityContext))
				{
					$errorCode = 'ACCESS_DENIED';
				}
				elseif ($object instanceof Folder && $this->isOwnSubtree($object, $targetFolder))
				{
					$errorCode = 'OWN_SUBTREE_DESTINATION';
				}
				elseif ($operation === 'move' && !$object->canMove($securityContext, $targetFolder))
				{
					$errorCode = 'ACCESS_DENIED';
				}
				elseif (
					$operation === 'copy'
					&& $object instanceof Folder
					&& $this->isFolderCopyLimitExceeded($object)
				)
				{
					$errorCode = 'LIMIT_EXCEEDED';
				}
				elseif ($this->hasNameConflict(
					$targetFolder,
					$object->getName(),
					$objectType,
					$operation === 'move' ? $objectId : null,
				))
				{
					$errorCode = 'NAME_CONFLICT';
				}
				if ($errorCode !== 'ACCESS_DENIED')
				{
					$preview['source'] = $this->formatObject($object);
					if ($operation === 'rename')
					{
						$preview['newName'] = $newName;
					}
				}
			}

			if ($errorCode !== null)
			{
				$preview['status'] = mb_strtolower($errorCode);
			}
			$result[] = [
				'object' => $object,
				'newName' => $newName,
				'preview' => $preview,
				'errorCode' => $errorCode,
			];
		}

		return $result;
	}

	private function isOwnSubtree(Folder $sourceFolder, Folder $targetFolder): bool
	{
		$sourceId = (int)$sourceFolder->getRealObjectId();
		$targetId = (int)$targetFolder->getRealObjectId();
		if ($sourceId === $targetId)
		{
			return true;
		}

		$ancestorIds = array_map(
			'intval',
			array_column(ObjectPathTable::getAncestors($targetId), 'PARENT_ID'),
		);

		return in_array($sourceId, $ancestorIds, true);
	}

	private function isFolderCopyLimitExceeded(Folder $folder): bool
	{
		$rows = ObjectPathTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=PARENT_ID' => $folder->getRealObjectId(),
				'!=OBJECT_ID' => $folder->getRealObjectId(),
			],
			'limit' => self::MAX_FOLDER_COPY_OBJECTS,
		])->fetchAll();

		return count($rows) >= self::MAX_FOLDER_COPY_OBJECTS;
	}

	private function isFolderCopyComplete(Folder $sourceFolder, Folder $copiedFolder): bool
	{
		return ObjectPathTable::getCount(['=PARENT_ID' => $sourceFolder->getRealObjectId()])
			=== ObjectPathTable::getCount(['=PARENT_ID' => $copiedFolder->getRealObjectId()]);
	}

	public function resolveAccessSubjects(int $userId, string $query, array $subjectTypes, int $limit): array
	{
		$query = trim($query);
		if ($query === '')
		{
			throw new McpException('query is required to resolve access subject.');
		}

		$limit = $this->normalizeLimit($limit);
		$subjectTypes = $this->normalizeSubjectTypes($subjectTypes);
		$limitations = $this->buildAccessSubjectLimitations($subjectTypes);
		$searchLimit = min(self::MAX_LIMIT, $limit + 1);

		$items = [];
		if (in_array('user', $subjectTypes, true) && count($items) < $searchLimit)
		{
			$items = array_merge($items, $this->findUserAccessSubjects($userId, $query, $searchLimit - count($items)));
		}
		if (in_array('department', $subjectTypes, true) && count($items) < $searchLimit)
		{
			$items = array_merge($items, $this->findDepartmentAccessSubjects($userId, $query, $searchLimit - count($items)));
		}
		if (in_array('socialnetwork_group', $subjectTypes, true) && count($items) < $searchLimit)
		{
			$items = array_merge($items, $this->findSocialnetworkGroupAccessSubjects($userId, $query, $searchLimit - count($items)));
		}
		$items = $this->deduplicateAccessSubjects($items);
		$hasMore = count($items) > $limit;
		$status = count($items) > 1 ? 'ambiguous' : (empty($items) ? 'not_found' : 'success');
		if (!empty($limitations))
		{
			$status = 'partial';
		}

		return [
			'query' => $query,
			'items' => array_slice($items, 0, $limit),
			'total' => count($items),
			'hasMore' => $hasMore,
			'limitations' => $limitations,
			'resultMeta' => $this->buildResultMeta($hasMore, $limitations, $status),
		];
	}

	public function deleteFiles(
		int $userId,
		array $fileIds,
		string $deletionType,
		bool $confirmed,
	): array
	{
		if (count($fileIds) > self::MAX_DELETE_FILE_IDS)
		{
			throw new McpException('fileIds must contain no more than ' . self::MAX_DELETE_FILE_IDS . ' file IDs.');
		}

		$fileIds = array_values(array_unique(array_filter($fileIds, static fn(int $id): bool => $id > 0)));
		if (empty($fileIds))
		{
			throw new McpException('fileIds must contain at least one positive file ID.');
		}
		sort($fileIds, SORT_NUMERIC);
		$deletionType = $deletionType === 'permanent' ? 'permanent' : 'to_trash';
		$resolved = $this->resolveDeleteTargets($userId, $fileIds, $deletionType);

		if (!$confirmed)
		{
			return [
				'deleted' => [],
				'notDeleted' => $resolved['notDeleted'],
				'targets' => $resolved['targets'],
				'itemOutcomes' => $resolved['itemOutcomes'],
				'confirmationRequired' => true,
				'defaultDeletionType' => 'to_trash',
				'warning' => $this->buildDeleteWarning($fileIds, $deletionType),
				'resultMeta' => $this->buildResultMeta(false, [], 'requires_confirmation'),
			];
		}

		$deleted = [];
		$notDeleted = $resolved['notDeleted'];
		$targetsById = array_column($resolved['targets'], null, 'id');
		$itemOutcomes = array_values(array_filter(
			$resolved['itemOutcomes'],
			static fn(array $item): bool => $item['status'] !== 'ready',
		));
		foreach ($resolved['files'] as $fileId => $file)
		{
			try
			{
				$success = $deletionType === 'permanent' ? $file->delete($userId) : $file->markDeleted($userId);
			}
			catch (\Throwable)
			{
				$itemOutcomes[] = array_merge($targetsById[$fileId] ?? [], [
					'fileId' => $fileId,
					'status' => 'unknown_result',
					'errorCode' => 'DISK_DELETE_RESULT_UNKNOWN',
				]);
				$notDeleted[] = ['fileId' => $fileId, 'reason' => 'The operation result is unknown; verify file state before retrying.'];

				continue;
			}
			if ($success)
			{
				$target = $targetsById[$fileId] ?? [];
				$deleted[] = array_merge($target, ['fileId' => $fileId, 'deletionType' => $deletionType]);
				$itemOutcomes[] = array_merge($target, [
					'fileId' => $fileId,
					'status' => 'success',
					'effect' => $deletionType === 'permanent' ? 'deleted_permanently' : 'moved_to_trash',
					'reversible' => $deletionType !== 'permanent',
				]);
			}
			else
			{
				$notDeleted[] = ['fileId' => $fileId, 'reason' => 'Disk operation failed.'];
				$itemOutcomes[] = array_merge($targetsById[$fileId] ?? [], [
					'fileId' => $fileId,
					'status' => 'failed',
					'effect' => null,
					'reversible' => null,
				]);
			}
		}
		$hasUnknownResult = (bool)array_filter($itemOutcomes, static fn(array $item): bool => $item['status'] === 'unknown_result');
		$status = $hasUnknownResult
			? 'unknown_result'
			: ($notDeleted ? ($deleted ? 'partial' : 'failed') : 'success');

		return [
			'deleted' => $deleted,
			'notDeleted' => $notDeleted,
			'deletedCount' => count($deleted),
			'notDeletedCount' => count($notDeleted),
			'deletionType' => $deletionType === 'permanent' ? 'permanent' : 'to_trash',
			'warning' => $this->buildDeleteWarning($fileIds, $deletionType),
			'itemOutcomes' => $itemOutcomes,
			'resultMeta' => $this->buildResultMeta(false, [], $status),
		];
	}

	private function searchFilesInGlobalWindow(
		int $userId,
		array $filter,
		int $limit,
		int $offset,
	): array
	{
		$rows = ObjectTable::getList([
			'select' => [
				'ID',
				'NAME',
				'TYPE',
				'TYPE_FILE',
				'DELETED_TYPE',
				'STORAGE_ID',
				'PARENT_ID',
				'SIZE',
				'CREATE_TIME',
				'UPDATE_TIME',
				'CREATED_BY',
			],
			'order' => ['ID' => 'DESC'],
			'limit' => self::GLOBAL_SEARCH_SCAN_LIMIT + 1,
		])->fetchAll();
		$isScanLimitReached = count($rows) > self::GLOBAL_SEARCH_SCAN_LIMIT;
		$rows = array_slice($rows, 0, self::GLOBAL_SEARCH_SCAN_LIMIT);

		$supportedStorageIds = [];
		$storageIds = array_values(array_unique(array_map('intval', array_column($rows, 'STORAGE_ID'))));
		foreach (Storage::loadBatchById($storageIds) as $storage)
		{
			if ($this->getSupportedStoragePolicy()->supports($storage))
			{
				$supportedStorageIds[(int)$storage->getId()] = true;
			}
		}

		$candidateRows = [];
		$candidateObjects = [];
		foreach ($rows as $row)
		{
			if (!isset($supportedStorageIds[(int)$row['STORAGE_ID']]))
			{
				continue;
			}
			if ((int)$row['TYPE'] !== (int)$filter['=TYPE'])
			{
				continue;
			}
			if ((int)$row['DELETED_TYPE'] !== (int)$filter['=DELETED_TYPE'])
			{
				continue;
			}
			if ((int)$row['CREATED_BY'] !== (int)$filter['=CREATED_BY'])
			{
				continue;
			}
			if ((int)$row['TYPE_FILE'] !== (int)$filter['=TYPE_FILE'])
			{
				continue;
			}

			$createTime = $row['CREATE_TIME'];
			if (isset($filter['>=CREATE_TIME']) && $createTime < $filter['>=CREATE_TIME'])
			{
				continue;
			}
			if (isset($filter['<=CREATE_TIME']) && $createTime > $filter['<=CREATE_TIME'])
			{
				continue;
			}

			$candidateRows[(int)$row['ID']] = $row;
			$candidateObjects[] = BaseObject::buildFromArray($row);
		}

		$readableObjectIds = $this->loadReadableObjectIds(new DiskSecurityContext($userId), $candidateObjects);
		$matchedRows = array_values(array_filter(
			$candidateRows,
			static fn(array $row): bool => isset($readableObjectIds[(int)$row['ID']]),
		));
		usort($matchedRows, static function (array $left, array $right): int {
			$dateOrder = $right['CREATE_TIME']->getTimestamp() <=> $left['CREATE_TIME']->getTimestamp();

			return $dateOrder !== 0 ? $dateOrder : (int)$right['ID'] <=> (int)$left['ID'];
		});

		$hasMore = count($matchedRows) > $offset + $limit;
		$pageRows = array_slice($matchedRows, $offset, $limit);
		$limitations = $isScanLimitReached ? [[
			'code' => 'SEARCH_SCAN_LIMIT_REACHED',
			'scanLimit' => self::GLOBAL_SEARCH_SCAN_LIMIT,
			'scanOrder' => ['id_desc'],
		]] : [];

		return [
			'items' => $this->formatObjectRows($pageRows, 'CREATE_TIME'),
			'limit' => $limit,
			'offset' => $offset,
			'hasMore' => $hasMore,
			'limitations' => $limitations,
		];
	}

	protected function getObjectsPage(
		int $userId,
		array $filter,
		array $order,
		int $limit,
		int $offset,
		bool $includePublicLink,
	): array
	{
		$select = ['ID', 'NAME', 'TYPE', 'STORAGE_ID', 'PARENT_ID', 'SIZE', 'CREATE_TIME', 'UPDATE_TIME', 'CREATED_BY'];

		$parameters = [
			'select' => $select,
			'filter' => $filter,
			'order' => $order,
			'limit' => $limit + 1,
			'offset' => $offset,
			'runtime' => $includePublicLink ? [
				new ReferenceField(
					'PUBLIC_LINK',
					ExternalLinkTable::class,
					['=this.ID' => 'ref.OBJECT_ID'],
					['join_type' => 'INNER'],
				),
			] : [],
		];
		if ($includePublicLink)
		{
			$parameters['group'] = $select;
		}

		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			new DiskSecurityContext($userId),
			$parameters,
			['ID', 'CREATED_BY'],
		);

		$rows = ObjectTable::getList($parameters)->fetchAll();

		$hasMore = count($rows) > $limit;
		$relevantDateField = array_key_first($order);

		return [
			'items' => $this->formatObjectRows(array_slice($rows, 0, $limit), $relevantDateField),
			'limit' => $limit,
			'offset' => $offset,
			'hasMore' => $hasMore,
			'resultMeta' => $this->buildResultMeta($hasMore),
		];
	}

	private function searchFilesByName(int $userId, string $query, array $filter, int $limit, int $offset): array
	{
		$finderLimit = $limit + $offset + 1;
		$fileFinder = new StorageFileFinder(
			userId: $userId,
			options: new StorageFileFinderOptions(
				limit: $finderLimit,
				proxyTypes: $this->getSupportedStoragePolicy()->getSupportedEntityTypes(),
				additionalFilter: $filter,
			),
		);
		$objectIds = $fileFinder->findIdsByText($query);
		$pageIds = array_slice($objectIds, $offset, $limit);
		if (empty($pageIds))
		{
			$hasMore = count($objectIds) > ($offset + $limit);

			return [
				'items' => [],
				'limit' => $limit,
				'offset' => $offset,
				'hasMore' => $hasMore,
				'resultMeta' => $this->buildResultMeta($hasMore, [], $hasMore ? 'success' : 'not_found'),
			];
		}

		$rowsById = [];
		$rows = ObjectTable::getList([
			'select' => ['ID', 'NAME', 'TYPE', 'STORAGE_ID', 'PARENT_ID', 'SIZE', 'CREATE_TIME', 'UPDATE_TIME', 'CREATED_BY'],
			'filter' => ['@ID' => $pageIds],
		])->fetchAll();
		foreach ($rows as $row)
		{
			$rowsById[(int)$row['ID']] = $row;
		}

		$items = [];
		foreach ($pageIds as $objectId)
		{
			if (isset($rowsById[(int)$objectId]))
			{
				$items[] = $rowsById[(int)$objectId];
			}
		}
		$hasMore = count($objectIds) > ($offset + $limit);

		return [
			'items' => $this->formatObjectRows($items),
			'limit' => $limit,
			'offset' => $offset,
			'hasMore' => $hasMore,
			'resultMeta' => $this->buildResultMeta($hasMore),
		];
	}

	private function searchDuplicates(int $userId, array $filter, int $limit, int $offset): array
	{
		$parameters = [
			'select' => ['ID', 'NAME', 'TYPE', 'STORAGE_ID', 'PARENT_ID', 'SIZE', 'CREATE_TIME', 'UPDATE_TIME', 'CREATED_BY'],
			'filter' => $filter,
			'order' => ['SIZE' => 'DESC', 'NAME' => 'ASC', 'ID' => 'DESC'],
			'limit' => self::DUPLICATE_SCAN_LIMIT + 1,
		];

		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			new DiskSecurityContext($userId),
			$parameters,
			['ID', 'CREATED_BY'],
		);

		$rows = ObjectTable::getList($parameters)->fetchAll();
		$isScanLimitReached = count($rows) > self::DUPLICATE_SCAN_LIMIT;
		if ($isScanLimitReached)
		{
			$rows = array_slice($rows, 0, self::DUPLICATE_SCAN_LIMIT);
		}

		$groups = [];
		foreach ($rows as $row)
		{
			$key = mb_strtolower((string)$row['NAME']) . ':' . (int)$row['SIZE'];
			$groups[$key][] = $row;
		}

		$duplicates = array_values(array_filter($groups, static fn(array $group): bool => count($group) > 1));
		$sliced = array_slice($duplicates, $offset, $limit);
		$sliced = array_map(fn(array $group): array => $this->formatObjectRows($group), $sliced);
		$hasMore = count($duplicates) > ($offset + $limit);
		$limitations = $isScanLimitReached ? [[
			'code' => 'DUPLICATE_SCAN_LIMIT_REACHED',
			'scanLimit' => self::DUPLICATE_SCAN_LIMIT,
			'scanOrder' => ['size_desc', 'name_asc', 'id_desc'],
		]] : [];

		return [
			'duplicateGroups' => $sliced,
			'limit' => $limit,
			'offset' => $offset,
			'hasMore' => $hasMore,
			'scanLimit' => self::DUPLICATE_SCAN_LIMIT,
			'scanLimitReached' => $isScanLimitReached,
			'isComplete' => !$isScanLimitReached,
			'grouping' => [
				'fields' => ['normalizedName', 'size'],
				'scope' => 'readable_files',
			],
			'limitations' => $limitations,
			'resultMeta' => $this->buildResultMeta($hasMore, $limitations),
		];
	}

	private function buildObjectScopeFilter(int $userId, array $options): array
	{
		$filter = [
			'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
			'STORAGE.USE_INTERNAL_RIGHTS' => true,
			'=STORAGE.MODULE_ID' => Driver::INTERNAL_MODULE_ID,
			'@STORAGE.ENTITY_TYPE' => $this->getSupportedStoragePolicy()->getSupportedEntityTypes(),
		];

		if (isset($options['_resolvedStorageIds']))
		{
			$filter['@STORAGE_ID'] = $options['_resolvedStorageIds'] ?: [-1];
		}
		elseif (!empty($options['storageId']))
		{
			$this->ensureSupportedSearchStorage((int)$options['storageId']);
			$filter['=STORAGE_ID'] = (int)$options['storageId'];
		}

		if (!empty($options['folderId']))
		{
			$folder = $this->resolveFolder($userId, (int)$options['folderId'], null, null);
			$securityContext = $folder->getStorage()->getSecurityContext($userId);
			if (!$folder->canRead($securityContext))
			{
				throw new McpException("Access denied for folder {$folder->getId()}.");
			}

			$this->ensureSupportedSearchStorage((int)$folder->getStorageId());
			$filter['=PATH_CHILD.PARENT_ID'] = $folder->getRealObjectId();
		}

		return $filter;
	}

	private function prepareSearchStorageScope(int $userId, array &$options): array
	{
		if (!empty($options['storageId']))
		{
			$storageIds = [(int)$options['storageId']];
		}
		else
		{
			$storageIds = array_values(array_unique(array_filter(
				array_map('intval', (array)($options['storageIds'] ?? [])),
				static fn(int $id): bool => $id > 0,
			)));
		}
		if (!$storageIds)
		{
			return [];
		}
		if (count($storageIds) > self::MAX_LIMIT)
		{
			throw new McpException('storageIds must contain no more than 50 storage IDs.');
		}

		$resolved = [];
		$limitations = [];
		foreach ($storageIds as $storageId)
		{
			$storage = Storage::loadById($storageId);
			$root = $storage?->getRootObject();
			if (!$storage || !$root || !$this->getSupportedStoragePolicy()->supports($storage))
			{
				$limitations[] = ['code' => 'STORAGE_UNAVAILABLE_OR_UNSUPPORTED', 'storageId' => $storageId];

				continue;
			}
			if (!$root->canRead($storage->getSecurityContext($userId)))
			{
				$limitations[] = ['code' => 'STORAGE_ACCESS_DENIED', 'storageId' => $storageId];

				continue;
			}
			$resolved[] = $storageId;
		}
		$options['_resolvedStorageIds'] = $resolved;

		return $limitations;
	}

	private function ensureSupportedSearchStorage(int $storageId): void
	{
		$this->loadSupportedStorage($storageId, 'search_files');
	}

	private function loadSupportedStorage(int $storageId, string $toolName): Storage
	{
		$storage = Storage::loadById($storageId);
		if (!$storage)
		{
			throw new McpException("Storage {$storageId} not found.");
		}
		$this->ensureSupportedStorage($storage, $toolName);

		return $storage;
	}

	private function ensureSupportedStorage(Storage $storage, string $toolName): void
	{
		if (!$this->getSupportedStoragePolicy()->supports($storage))
		{
			$storageId = (int)$storage->getId();

			throw new McpException("Storage {$storageId} is not supported by {$toolName}. Use a user, group or common Disk storage.");
		}
	}

	private function resolveFolderOrStorageRoot(
		int $userId,
		?int $folderId,
		?string $folderName,
		?int $storageId,
		?string $storageName,
	): Folder|array
	{
		if ($folderId !== null || $folderName !== null)
		{
			return $this->resolveFolder($userId, $folderId, $folderName, $storageId, $storageName);
		}

		return $this->resolveStorageRoot($userId, $storageId, $storageName);
	}

	private function resolveStorageRoot(int $userId, ?int $storageId, ?string $storageName): Folder|array
	{
		if ($storageId === null && $storageName === null)
		{
			$storage = Driver::getInstance()->getStorageByUserId($userId);
			if (!$storage)
			{
				throw new McpException('Personal Disk storage of the current user is not available. Pass storageId or folderId.');
			}

			$this->ensureSupportedStorage($storage, 'get_folder_contents');
		}
		else
		{
			$storage = $this->resolveStorage($userId, $storageId, $storageName);
			if (is_array($storage))
			{
				return $storage;
			}
		}

		$rootFolder = $storage->getRootObject();
		if (!$rootFolder)
		{
			$resolvedStorageId = (int)$storage->getId();

			throw new McpException("Storage {$resolvedStorageId} has no root folder.");
		}

		return $rootFolder;
	}

	private function resolveStorage(int $userId, ?int $storageId, ?string $storageName): Storage|array
	{
		if ($storageId !== null)
		{
			$storage = $this->loadSupportedStorage($storageId, 'get_folder_contents');
			if ($storageName === null)
			{
				return $storage;
			}

			$rootFolder = $storage->getRootObject();
			if (!$rootFolder)
			{
				throw new McpException("Storage {$storageId} has no root folder.");
			}
			if (!$rootFolder->canRead($storage->getSecurityContext($userId)))
			{
				throw new McpException("Access denied for folder {$rootFolder->getId()}.");
			}
			if ($this->getStorageDisplayName($storage) !== $storageName)
			{
				return $this->buildEntityResolutionResponse(
					'storage',
					'name_id_mismatch',
					[$this->formatStorageCandidate($storage)],
					[['code' => 'NAME_ID_MISMATCH', 'field' => 'storageName']],
				);
			}

			return $storage;
		}

		$matches = [];
		$securityContext = new DiskSecurityContext($userId);
		$storages = $this->getReadableStoragesForNameScan($securityContext);
		$scanTruncated = count($storages) > static::STORAGE_NAME_SCAN_LIMIT;
		foreach (array_slice($storages, 0, static::STORAGE_NAME_SCAN_LIMIT) as $storage)
		{
			if (
				$this->getSupportedStoragePolicy()->supports($storage)
				&& $this->getStorageDisplayName($storage) === $storageName
			)
			{
				$matches[] = $storage;
			}
		}
		$limitations = $scanTruncated
			? [[
				'code' => 'STORAGE_NAME_SCAN_LIMIT_REACHED',
				'scanLimit' => static::STORAGE_NAME_SCAN_LIMIT,
				'order' => ['storageId' => 'asc'],
			]]
			: [];
		if ($scanTruncated && count($matches) < 2)
		{
			return $this->buildEntityResolutionResponse(
				'storage',
				'partial',
				array_map($this->formatStorageCandidate(...), $matches),
				[],
				$limitations,
			);
		}

		if (count($matches) === 0)
		{
			return $this->buildEntityResolutionResponse('storage', 'not_found');
		}
		if (count($matches) > 1)
		{
			return $this->buildEntityResolutionResponse(
				'storage',
				'ambiguous',
				array_map($this->formatStorageCandidate(...), array_slice($matches, 0, 6)),
				[],
				$limitations,
			);
		}

		return $matches[0];
	}

	protected function getReadableStoragesForNameScan(DiskSecurityContext $securityContext): array
	{
		return Storage::getReadableList($securityContext, [
			'filter' => ['@STORAGE.ENTITY_TYPE' => $this->getSupportedStoragePolicy()->getSupportedEntityTypes()],
			'order' => ['STORAGE.ID' => 'ASC'],
			'limit' => static::STORAGE_NAME_SCAN_LIMIT + 1,
		]);
	}

	private function getStorageDisplayName(Storage $storage): string
	{
		return (string)$storage->getProxyType()->getEntityTitle();
	}

	private function formatStorageCandidate(Storage $storage): array
	{
		$proxyType = $storage->getProxyType();
		$type = match (true)
		{
			$proxyType instanceof ProxyType\User => 'user',
			$proxyType instanceof ProxyType\Group => 'group',
			$proxyType instanceof ProxyType\Common => 'common',
			default => 'unknown',
		};

		return [
			'storageId' => (int)$storage->getId(),
			'name' => $this->getStorageDisplayName($storage),
			'type' => $type,
		];
	}

	private function resolveFolder(
		int $userId,
		?int $folderId,
		?string $folderName,
		?int $storageId,
		?string $storageName = null,
	): Folder|array
	{
		if ($folderId !== null)
		{
			$folder = Folder::loadById($folderId);
			if (!$folder)
			{
				throw new McpException("Folder {$folderId} not found.");
			}

			$this->ensureSupportedStorage($folder->getStorage(), 'Disk MCP');
			$securityContext = $folder->getStorage()->getSecurityContext($userId);
			if (!$folder->canRead($securityContext))
			{
				throw new McpException("Access denied for folder {$folderId}.");
			}
			if ($folderName !== null && $folder->getName() !== $folderName)
			{
				return $this->buildEntityResolutionResponse(
					'folder',
					'name_id_mismatch',
					[$this->formatObject($folder)],
					[['code' => 'NAME_ID_MISMATCH', 'field' => 'folderName']],
				);
			}
			if ($storageId !== null && (int)$folder->getStorageId() !== $storageId)
			{
				return $this->buildEntityResolutionResponse(
					'folder',
					'name_id_mismatch',
					[$this->formatObject($folder)],
					[['code' => 'NAME_ID_MISMATCH', 'field' => 'storageId']],
				);
			}
			if ($storageName !== null && $this->getStorageDisplayName($folder->getStorage()) !== $storageName)
			{
				return $this->buildEntityResolutionResponse(
					'folder',
					'name_id_mismatch',
					[$this->formatObject($folder)],
					[['code' => 'NAME_ID_MISMATCH', 'field' => 'storageName']],
				);
			}

			return $folder;
		}

		if ($folderName === null)
		{
			throw new McpException('folderId or folderName is required.');
		}
		if ($storageId === null && $storageName === null)
		{
			throw new McpException('storageId or storageName is required when resolving folderName. Use folderId when known.');
		}

		$storage = $this->resolveStorage($userId, $storageId, $storageName);
		if (is_array($storage))
		{
			return $storage;
		}
		$resolvedStorageId = (int)$storage->getId();

		$filter = [
			'=TYPE' => ObjectTable::TYPE_FOLDER,
			'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
		];
		$filter['=STORAGE_ID'] = $resolvedStorageId;

		$parameters = [
			'select' => ['ID', 'NAME', 'TYPE', 'STORAGE_ID', 'PARENT_ID', 'SIZE', 'CREATE_TIME', 'UPDATE_TIME', 'CREATED_BY'],
			'filter' => $filter,
			'order' => ['NAME' => 'ASC', 'ID' => 'ASC'],
		];
		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			new DiskSecurityContext($userId),
			$parameters,
			['ID', 'CREATED_BY'],
		);
		$readableRows = ObjectNameKey::findAllSameName($parameters, $folderName, 6);

		if (count($readableRows) === 0)
		{
			return $this->buildEntityResolutionResponse('folder', 'not_found');
		}
		if (count($readableRows) > 1)
		{
			return $this->buildEntityResolutionResponse('folder', 'ambiguous', $this->formatObjectRows($readableRows));
		}

		return Folder::loadById((int)$readableRows[0]['ID']);
	}

	private function resolveFile(
		int $userId,
		?int $fileId,
		?string $fileName,
		?int $storageId,
		?int $folderId,
	): File|array
	{
		if ($fileId !== null)
		{
			$file = File::loadById($fileId);
			if (!$file)
			{
				throw new McpException("File {$fileId} not found.");
			}

			$this->ensureSupportedStorage($file->getStorage(), 'get_file_history');
			$securityContext = $file->getStorage()->getSecurityContext($userId);
			if (!$file->canRead($securityContext))
			{
				throw new McpException("Access denied for file {$fileId}.");
			}
			if ($fileName !== null && $file->getName() !== $fileName)
			{
				return $this->buildEntityResolutionResponse(
					'file',
					'name_id_mismatch',
					[$this->formatObject($file)],
					[['code' => 'NAME_ID_MISMATCH', 'field' => 'fileName']],
				);
			}
			if ($storageId !== null && (int)$file->getStorageId() !== $storageId)
			{
				return $this->buildEntityResolutionResponse(
					'file',
					'name_id_mismatch',
					[$this->formatObject($file)],
					[['code' => 'NAME_ID_MISMATCH', 'field' => 'storageId']],
				);
			}

			return $file;
		}

		if ($fileName === null)
		{
			throw new McpException('fileId or fileName is required.');
		}

		$filter = [
			'=TYPE' => ObjectTable::TYPE_FILE,
		];
		if ($storageId !== null)
		{
			$this->ensureSupportedSearchStorage($storageId);
			$filter['=STORAGE_ID'] = $storageId;
		}
		if ($folderId !== null)
		{
			$folder = $this->resolveFolder($userId, $folderId, null, null);
			if (is_array($folder))
			{
				return $folder;
			}
			$securityContext = $folder->getStorage()->getSecurityContext($userId);
			if (!$folder->canRead($securityContext))
			{
				throw new McpException("Access denied for folder {$folder->getId()}.");
			}

			$this->ensureSupportedSearchStorage((int)$folder->getStorageId());
			$filter['=PARENT_ID'] = $folder->getRealObjectId();
		}

		$fileFinder = new StorageFileFinder(
			userId: $userId,
			options: new StorageFileFinderOptions(
				limit: 6,
				proxyTypes: $this->getSupportedStoragePolicy()->getSupportedEntityTypes(),
				additionalFilter: $filter,
			),
		);
		$objectIds = $fileFinder->findIdsByText($fileName);

		if (count($objectIds) === 0)
		{
			return $this->buildEntityResolutionResponse('file', 'not_found');
		}
		if (count($objectIds) > 1)
		{
			$rowsById = [];
			$rows = ObjectTable::getList([
				'select' => ['ID', 'NAME', 'TYPE', 'STORAGE_ID', 'PARENT_ID', 'SIZE', 'CREATE_TIME', 'UPDATE_TIME', 'CREATED_BY'],
				'filter' => ['@ID' => $objectIds],
			])->fetchAll();
			foreach ($rows as $row)
			{
				$rowsById[(int)$row['ID']] = $row;
			}

			$items = [];
			foreach ($objectIds as $objectId)
			{
				if (isset($rowsById[(int)$objectId]))
				{
					$items[] = $rowsById[(int)$objectId];
				}
			}

			return $this->buildEntityResolutionResponse('file', 'ambiguous', $this->formatObjectRows($items));
		}

		return File::loadById((int)$objectIds[0]);
	}

	private function buildEntityResolutionResponse(
		string $entity,
		string $status,
		array $candidates = [],
		array $errors = [],
		array $limitations = [],
	): array
	{
		return [
			'resolution' => ['entity' => $entity, 'status' => $status],
			'candidates' => $candidates,
			'resultMeta' => $this->buildResultMeta(false, $limitations, $status, $errors),
		];
	}

	private function resolveSearchOwner(int $userId, array $options): array
	{
		$ownerId = (int)($options['ownerId'] ?? 0);
		$ownerName = trim((string)($options['ownerName'] ?? ''));
		if ($ownerId > 0)
		{
			return ['userId' => $ownerId];
		}
		if ($ownerName === '')
		{
			return [];
		}

		$candidates = $this->findUserAccessSubjects($userId, $ownerName, 3);
		if (count($candidates) === 1)
		{
			return ['userId' => (int)$candidates[0]['id']];
		}

		$status = empty($candidates) ? 'not_found' : 'ambiguous';

		return ['response' => $this->buildSearchResolutionResponse($status, $candidates, 'owner')];
	}

	private function resolveSearchDepartment(int $userId, array $options): array
	{
		$departmentId = (int)($options['departmentId'] ?? 0);
		$departmentName = trim((string)($options['departmentName'] ?? ''));
		if ($departmentId <= 0 && $departmentName === '')
		{
			return [];
		}

		$query = $departmentId > 0 ? 'DR' . $departmentId : $departmentName;
		$candidates = $this->findDepartmentAccessSubjects($userId, $query, 3);
		if ($departmentId > 0)
		{
			$candidates = array_values(array_filter(
				$candidates,
				static fn(array $candidate): bool => (int)$candidate['id'] === $departmentId,
			));
		}
		if (count($candidates) !== 1)
		{
			$status = empty($candidates) ? 'not_found' : 'ambiguous';

			return ['response' => $this->buildSearchResolutionResponse($status, $candidates, 'department')];
		}

		$userIds = [];
		$userResult = \CUser::GetList(
			'ID',
			'ASC',
			['ACTIVE' => 'Y', 'UF_DEPARTMENT' => (int)$candidates[0]['id']],
			['FIELDS' => ['ID'], 'NAV_PARAMS' => ['nTopCount' => self::DEPARTMENT_USER_SCAN_LIMIT + 1]],
		);
		while ($user = $userResult->Fetch())
		{
			$userIds[] = (int)$user['ID'];
		}
		$limited = count($userIds) > self::DEPARTMENT_USER_SCAN_LIMIT;
		$userIds = array_slice($userIds, 0, self::DEPARTMENT_USER_SCAN_LIMIT);

		return [
			'userIds' => $userIds ?: [-1],
			'limitations' => $limited ? [['code' => 'DEPARTMENT_USER_SCAN_LIMIT_REACHED']] : [],
		];
	}

	private function buildSearchResolutionResponse(string $status, array $candidates, string $entity): array
	{
		return [
			'items' => [],
			'candidates' => $candidates,
			'limit' => 0,
			'offset' => 0,
			'hasMore' => false,
			'resolution' => ['entity' => $entity, 'status' => $status],
			'resultMeta' => $this->buildResultMeta(false, [], $status),
		];
	}

	private function applyDateFilter(array &$filter, array $options, string $field): void
	{
		if (!empty($options['periodDays']))
		{
			$periodDays = (int)$options['periodDays'];
			if ($periodDays <= 0)
			{
				throw new McpException('periodDays must be a positive integer.');
			}
			$filter['>=' . $field] = DateTime::createFromTimestamp(time() - $periodDays * 86400);
		}
		foreach (['dateFrom' => '>=', 'dateTo' => '<='] as $option => $operator)
		{
			if (!empty($options[$option]))
			{
				try
				{
					$filter[$operator . $field] = new DateTime((string)$options[$option]);
				}
				catch (\Throwable)
				{
					throw new McpException("{$option} must be a valid date/time value.");
				}
			}
		}
		if (isset($filter['>=' . $field], $filter['<=' . $field]) && $filter['>=' . $field] > $filter['<=' . $field])
		{
			throw new McpException('dateFrom must be earlier than or equal to dateTo.');
		}
	}

	private function applyFileTypeFilter(array &$filter, array $options): void
	{
		$extension = mb_strtolower(ltrim(trim((string)($options['extension'] ?? '')), '.'));
		if ($extension !== '')
		{
			if (!preg_match('/^[a-z0-9]{1,16}$/', $extension))
			{
				throw new McpException('extension must contain 1 to 16 letters or digits.');
			}
			$filter['%NAME'] = '.' . $extension;
		}

		$fileType = mb_strtolower(trim((string)($options['fileType'] ?? '')));
		$typeFile = match ($fileType)
		{
			'image' => [TypeFile::IMAGE, TypeFile::VECTOR_IMAGE],
			'video' => TypeFile::VIDEO,
			'audio' => TypeFile::AUDIO,
			'pdf' => TypeFile::PDF,
			'document' => TypeFile::DOCUMENT,
			'text' => null,
			'', 'any' => null,
			default => throw new McpException("Unknown fileType '{$fileType}'."),
		};
		if (is_array($typeFile))
		{
			$filter['@TYPE_FILE'] = $typeFile;
		}
		elseif ($typeFile !== null)
		{
			$filter['=TYPE_FILE'] = $typeFile;
		}
		if ($fileType === 'text')
		{
			$filter['%=FILE_CONTENT.CONTENT_TYPE'] = 'text/%';
		}
	}

	private function buildResultMeta(
		bool $hasMore,
		array $limitations = [],
		string $status = 'success',
		array $errors = [],
	): array
	{
		$partial = !empty($limitations) || $status === 'partial';

		return [
			'status' => $partial && $status === 'success' ? 'partial' : $status,
			'hasMore' => $hasMore,
			'isComplete' => !$hasMore && !$partial && $status === 'success',
			'partial' => $partial,
			'nextCursor' => null,
			'limitations' => $limitations,
			'errors' => $errors,
		];
	}

	private function formatEffectiveRights(array $rights, RightsManager $rightsManager, array &$limitations = []): array
	{
		$rank = ['read' => 1, 'edit' => 2, 'full' => 3];
		$byAccessCode = [];
		$deniedAccessCodes = [];
		foreach ($rights as $right)
		{
			if (!empty($right['NEGATIVE']))
			{
				$deniedAccessCodes[(string)$right['ACCESS_CODE']] = true;

				continue;
			}
			$accessCode = (string)$right['ACCESS_CODE'];
			$level = $this->mapTaskNameToAccessLevel($rightsManager->getTaskNameById((int)$right['TASK_ID']));
			if (!isset($rank[$level]))
			{
				continue;
			}
			if (!isset($byAccessCode[$accessCode]) || $rank[$level] > $rank[$byAccessCode[$accessCode]])
			{
				$byAccessCode[$accessCode] = $level;
			}
		}
		$byAccessCode = array_diff_key($byAccessCode, $deniedAccessCodes);

		$names = (new \CAccess())->GetNames(array_keys($byAccessCode));
		$subjects = [];
		foreach ($byAccessCode as $accessCode => $accessLevel)
		{
			$name = trim((string)($names[$accessCode]['name'] ?? ''));
			if ($name === '')
			{
				$limitations[] = ['code' => 'subject_unavailable'];

				continue;
			}
			$subjects[] = ['type' => $this->detectAccessSubjectType($accessCode), 'id' => $this->extractAccessCodeId($accessCode), 'name' => $name, 'accessLevel' => $accessLevel];
		}

		return $subjects;
	}

	private function describeAccessCodes(array $accessCodes): array
	{
		$names = (new \CAccess())->GetNames($accessCodes);
		$result = [];
		foreach ($accessCodes as $accessCode)
		{
			$result[] = [
				'accessCode' => $accessCode,
				'type' => $this->detectAccessSubjectType($accessCode),
				'id' => $this->extractAccessCodeId($accessCode),
				'name' => (string)($names[$accessCode]['name'] ?? $accessCode),
			];
		}

		return $result;
	}

	private function resolveDeleteTargets(int $userId, array $fileIds, string $deletionType): array
	{
		$filesById = [];
		foreach (File::getModelList([
			'select' => ['*'],
			'filter' => ['@ID' => $fileIds, '=TYPE' => ObjectTable::TYPE_FILE],
			'with' => ['STORAGE'],
		]) as $file)
		{
			$filesById[(int)$file->getId()] = $file;
		}
		$formattedById = [];
		foreach ($this->formatObjects(array_values($filesById)) as $item)
		{
			$formattedById[$item['id']] = $item;
		}

		$targets = [];
		$readyFiles = [];
		$notDeleted = [];
		$itemOutcomes = [];
		foreach ($fileIds as $fileId)
		{
			$file = $filesById[$fileId] ?? null;
			$reason = null;
			$status = 'ready';
			if (!$file)
			{
				$reason = 'File not found.';
				$status = 'not_found';
			}
			elseif (!$this->getSupportedStoragePolicy()->supports($file->getStorage()))
			{
				$reason = 'Unsupported storage. delete_files supports user, group and common Disk storages only.';
				$status = 'unsupported';
			}
			elseif ($deletionType === 'to_trash' && (int)$file->getDeletedType() !== ObjectTable::DELETED_TYPE_NONE)
			{
				$reason = 'File is already in the trash.';
				$status = 'skipped';
			}
			elseif (!($deletionType === 'permanent'
				? $file->canDelete($file->getStorage()->getSecurityContext($userId))
				: $file->canMarkDeleted($file->getStorage()->getSecurityContext($userId))))
			{
				$reason = 'Access denied.';
				$status = 'access_denied';
			}

			if ($reason !== null)
			{
				$notDeleted[] = ['fileId' => $fileId, 'reason' => $reason];
				$itemOutcomes[] = ['fileId' => $fileId, 'status' => $status, 'reason' => $reason];

				continue;
			}

			$target = $formattedById[$fileId];
			$target['effect'] = $deletionType === 'permanent' ? 'deleted_permanently' : 'moved_to_trash';
			$target['reversible'] = $deletionType !== 'permanent';
			$targets[] = $target;
			$readyFiles[$fileId] = $file;
			$itemOutcomes[] = ['fileId' => $fileId, 'status' => 'ready', 'target' => $target];
		}

		return [
			'targets' => $targets,
			'files' => $readyFiles,
			'notDeleted' => $notDeleted,
			'itemOutcomes' => $itemOutcomes,
		];
	}

	private function formatObjects(array $objects): array
	{
		$rows = [];
		$models = [];
		foreach ($objects as $object)
		{
			$rows[] = [
				'ID' => $object->getId(),
				'NAME' => $object->getName(),
				'TYPE' => $object instanceof Folder ? ObjectTable::TYPE_FOLDER : ObjectTable::TYPE_FILE,
				'STORAGE_ID' => $object->getStorageId(),
				'PARENT_ID' => $object->getParentId(),
				'SIZE' => $object instanceof File ? $object->getSize() : null,
				'CREATE_TIME' => $object->getCreateTime(),
				'UPDATE_TIME' => $object->getUpdateTime(),
				'CREATED_BY' => $object->getCreatedBy(),
			];
			$models[(int)$object->getId()] = $object;
		}

		return $this->formatObjectRows($rows, 'UPDATE_TIME', $models);
	}

	private function formatObjectRows(array $rows, string $relevantDateField = 'UPDATE_TIME', array $models = []): array
	{
		if (!in_array($relevantDateField, ['CREATE_TIME', 'UPDATE_TIME'], true))
		{
			$relevantDateField = 'UPDATE_TIME';
		}
		$parentIds = array_values(array_unique(array_filter(array_column($rows, 'PARENT_ID'))));
		$parentNames = [];
		if ($parentIds)
		{
			foreach (ObjectTable::getList(['select' => ['ID', 'NAME'], 'filter' => ['@ID' => $parentIds]])->fetchAll() as $parent)
			{
				$parentNames[(int)$parent['ID']] = (string)$parent['NAME'];
			}
		}
		$userIds = array_values(array_unique(array_filter(array_column($rows, 'CREATED_BY'))));
		$userNames = [];
		if ($userIds)
		{
			foreach (\Bitrix\Main\UserTable::getList([
				'select' => ['ID', 'NAME', 'LAST_NAME'],
				'filter' => ['@ID' => $userIds],
			])->fetchAll() as $user)
			{
				$userNames[(int)$user['ID']] = $this->formatUserName($user['NAME'], $user['LAST_NAME']);
			}
		}
		if (!$models && $rows)
		{
			foreach (BaseObject::getModelList(['select' => ['*'], 'filter' => ['@ID' => array_column($rows, 'ID')], 'with' => ['STORAGE']]) as $model)
			{
				$models[(int)$model->getId()] = $model;
			}
		}
		if ($models)
		{
			\Bitrix\Disk\CrumbStorage::getInstance()->preloadByObjects(array_values($models));
		}

		$result = [];
		foreach ($rows as $row)
		{
			$item = $this->formatObjectRow($row);
			$model = $models[$item['id']] ?? null;
			$crumbs = $model ? \Bitrix\Disk\CrumbStorage::getInstance()->getByObject($model) : [];
			$item['location'] = $crumbs ? implode(' / ', $crumbs) : ($parentNames[$item['parentId']] ?? null);
			$item['owner'] = ['id' => $item['createdBy'], 'name' => $userNames[$item['createdBy']] ?? null];
			$item['relevantDate'] = $this->formatDateTime($row[$relevantDateField] ?? $row['UPDATE_TIME'] ?? null);
			$item['internalLink'] = $this->buildInternalLink($model);
			$result[] = $item;
		}

		return $result;
	}

	private function buildInternalLink(?BaseObject $object): ?string
	{
		if (!$object)
		{
			return null;
		}
		try
		{
			$urlManager = Driver::getInstance()->getUrlManager();
			if ($object instanceof File && $object->supportsUnifiedLink())
			{
				return $urlManager->getUnifiedLink($object, ['absolute' => true]);
			}

			$path = $object instanceof File
				? $urlManager->getPathFileDetail($object)
				: $urlManager->getPathFolderList($object);
			$absolutePath = preg_match('~^https?://~i', $path)
				? $path
				: rtrim($urlManager->getHostUrl(), '/') . '/' . ltrim($path, '/');

			return $urlManager->encodeUrn($absolutePath);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function collectInternalLinkLimitations(array $items): array
	{
		$limitations = [];
		foreach ($items as $item)
		{
			if (!array_key_exists('internalLink', $item) || $item['internalLink'] !== null)
			{
				continue;
			}

			$targetId = (int)($item['id'] ?? 0);
			$limitations[] = $targetId > 0
				? ['code' => 'INTERNAL_LINK_UNAVAILABLE', 'targetId' => $targetId]
				: ['code' => 'INTERNAL_LINK_UNAVAILABLE'];
		}

		return $limitations;
	}

	private function formatObject(BaseObject $object): array
	{
		return $this->formatObjects([$object])[0];
	}

	private function formatObjectRow(array $row): array
	{
		return [
			'id' => (int)$row['ID'],
			'name' => (string)$row['NAME'],
			'type' => (int)$row['TYPE'] === ObjectTable::TYPE_FOLDER ? 'folder' : 'file',
			'storageId' => (int)$row['STORAGE_ID'],
			'parentId' => isset($row['PARENT_ID']) ? (int)$row['PARENT_ID'] : null,
			'size' => isset($row['SIZE']) ? (int)$row['SIZE'] : null,
			'createTime' => $this->formatDateTime($row['CREATE_TIME'] ?? null),
			'updateTime' => $this->formatDateTime($row['UPDATE_TIME'] ?? null),
			'createdBy' => isset($row['CREATED_BY']) ? (int)$row['CREATED_BY'] : null,
		];
	}

	private function formatVersion(array $row): array
	{
		return [
			'id' => (int)$row['ID'],
			'fileId' => (int)$row['OBJECT_ID'],
			'name' => (string)$row['NAME'],
			'size' => isset($row['SIZE']) ? (int)$row['SIZE'] : null,
			'createTime' => $this->formatDateTime($row['CREATE_TIME'] ?? null),
			'createdBy' => isset($row['CREATED_BY']) ? (int)$row['CREATED_BY'] : null,
			'createdByName' => $this->formatUserName($row['CREATED_BY_NAME'] ?? null, $row['CREATED_BY_LAST_NAME'] ?? null),
		];
	}

	private function buildEditors(array $versions): array
	{
		$editors = [];
		foreach ($versions as $version)
		{
			$userId = (int)($version['createdBy'] ?? 0);
			if ($userId <= 0)
			{
				continue;
			}

			$editors[$userId] ??= [
				'userId' => $userId,
				'name' => $version['createdByName'],
				'versionsCount' => 0,
				'lastEditTime' => null,
			];
			$editors[$userId]['versionsCount']++;
			$editors[$userId]['lastEditTime'] ??= $version['createTime'];
		}

		return array_values($editors);
	}

	private function normalizeLimit(int $limit): int
	{
		return max(1, min($limit, self::MAX_LIMIT));
	}

	private function normalizeOffset(int $offset): int
	{
		$offset = max(0, $offset);
		if ($offset > self::MAX_OFFSET)
		{
			throw new McpException('offset must be less than or equal to ' . self::MAX_OFFSET . ' for search_files.');
		}

		return $offset;
	}

	private function mapAccessLevelToTaskName(string $accessLevel): string
	{
		return match ($accessLevel)
		{
			'read' => RightsManager::TASK_READ,
			'edit' => RightsManager::TASK_EDIT,
			'full' => RightsManager::TASK_FULL,
			default => throw new McpException("Unknown accessLevel '{$accessLevel}'."),
		};
	}

	private function isValidAccessCode(string $accessCode): bool
	{
		return (bool)preg_match('/^(?:AU|CR|U\d+|IU\d+|DR\d+|G\d+|SG\d+_[A-Z]\d*)$/', $accessCode);
	}

	private function isManageableSharingAccessCode(string $accessCode): bool
	{
		return (bool)preg_match('/^(?:U\d+|DR\d+|SG\d+_K)$/', $accessCode);
	}

	private function canGrantAccessToSubject(int $userId, string $accessCode): bool
	{
		if (in_array($accessCode, ['AU', 'CR'], true))
		{
			return true;
		}
		if (preg_match('/^DR\d+$/', $accessCode))
		{
			return $this->isDepartmentAvailableForGrant($userId, $accessCode);
		}
		if (preg_match('/^G\d+$/', $accessCode))
		{
			return false;
		}

		$sharingEntity = $this->normalizeAccessCodeForSharingVisibilityCheck($accessCode);
		if ($sharingEntity === null)
		{
			return false;
		}

		return Sharing::hasRightToKnowAboutEntity($userId, $sharingEntity);
	}

	private function normalizeAccessCodeForSharingVisibilityCheck(string $accessCode): ?string
	{
		if (preg_match('/^IU(\d+)$/', $accessCode, $matches))
		{
			return 'U' . $matches[1];
		}
		if (preg_match('/^U\d+$/', $accessCode))
		{
			return $accessCode;
		}
		if (preg_match('/^(SG\d+)_[A-Z]\d*$/', $accessCode, $matches))
		{
			return $matches[1];
		}

		return null;
	}

	private function isDepartmentAvailableForGrant(int $userId, string $accessCode): bool
	{
		$destination = Destination::getRightsDestination($userId);
		foreach (($destination['DEPARTMENT'] ?? []) as $key => $department)
		{
			$departmentAccessCode = $this->normalizeDepartmentAccessCode((string)($department['id'] ?? $key));
			if ($departmentAccessCode === $accessCode)
			{
				return true;
			}
		}

		return false;
	}

	private function normalizeSubjectTypes(array $subjectTypes): array
	{
		$allowedTypes = ['user', 'department', 'socialnetwork_group'];
		if (empty($subjectTypes))
		{
			return $allowedTypes;
		}

		$subjectTypes = array_values(array_intersect($subjectTypes, $allowedTypes));

		return $subjectTypes;
	}

	private function buildAccessSubjectLimitations(array $subjectTypes): array
	{
		$socialnetworkSubjectTypes = array_values(array_intersect(
			$subjectTypes,
			['user', 'department', 'socialnetwork_group'],
		));
		if (empty($socialnetworkSubjectTypes) || Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		return [[
			'code' => 'ACCESS_SUBJECT_SOURCE_UNAVAILABLE',
			'module' => 'socialnetwork',
			'subjectTypes' => $socialnetworkSubjectTypes,
		]];
	}

	private function findUserAccessSubjects(int $userId, string $query, int $limit): array
	{
		if ($limit <= 0 || !Loader::includeModule('socialnetwork'))
		{
			return [];
		}
		if (preg_match('/^(?:U|IU)(\d+)$/i', $query, $matches))
		{
			return $this->findUserAccessSubjectsByIds($userId, [(int)$matches[1]], $limit);
		}
		if (mb_strlen($query) < self::MIN_USER_SEARCH_QUERY_LENGTH)
		{
			return [];
		}

		$searchModified = '';
		$users = \CSocNetLogDestination::SearchUsers(
			[
				'SEARCH' => $query,
				'NAME_TEMPLATE' => \CSite::GetNameFormat(false),
				'SELF' => true,
				'CHECK_ACTIVITY' => true,
				'EMAIL_USERS' => false,
			],
			$searchModified,
		);

		$result = [];
		foreach ($users as $user)
		{
			$accessCode = (string)($user['id'] ?? '');
			if (
				!$this->isValidAccessCode($accessCode)
				|| !$this->canGrantAccessToSubject($userId, $accessCode)
			)
			{
				continue;
			}

			$result[] = [
				'accessCode' => $accessCode,
				'type' => 'user',
				'id' => $this->extractAccessCodeId($accessCode),
				'name' => (string)($user['name'] ?? $accessCode),
			];
			if (count($result) >= $limit)
			{
				break;
			}
		}

		return $result;
	}

	private function findUserAccessSubjectsByIds(int $actingUserId, array $userIds, int $limit): array
	{
		$userIds = array_values(array_unique(array_filter($userIds, static fn(int $userId): bool => $userId > 0)));
		$userIds = array_values(array_filter(
			$userIds,
			fn(int $targetUserId): bool => $this->canGrantAccessToSubject($actingUserId, 'U' . $targetUserId),
		));
		if (empty($userIds))
		{
			return [];
		}

		$users = \CSocNetLogDestination::GetUsers([
			'id' => $userIds,
			'NAME_TEMPLATE' => \CSite::GetNameFormat(false),
		]);

		$result = [];
		$allowedUserIds = array_flip($userIds);
		foreach ($users as $user)
		{
			$targetUserId = (int)($user['entityId'] ?? 0);
			$accessCode = (string)($user['id'] ?? '');
			if (
				$targetUserId <= 0
				|| !isset($allowedUserIds[$targetUserId])
				|| !$this->isValidAccessCode($accessCode)
			)
			{
				continue;
			}

			$result[] = [
				'accessCode' => $accessCode,
				'type' => 'user',
				'id' => $targetUserId,
				'name' => (string)($user['name'] ?? $accessCode),
			];
			if (count($result) >= $limit)
			{
				break;
			}
		}

		return $result;
	}

	private function findDepartmentAccessSubjects(int $userId, string $query, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$result = [];
		$destination = Destination::getRightsDestination($userId);
		foreach (($destination['DEPARTMENT'] ?? []) as $key => $department)
		{
			$accessCode = $this->normalizeDepartmentAccessCode((string)($department['id'] ?? $key));
			$name = (string)($department['name'] ?? $department['title'] ?? $accessCode);
			if (!$this->isValidAccessCode($accessCode))
			{
				continue;
			}
			if (!$this->matchesAccessSubjectQuery($query, $accessCode, $name))
			{
				continue;
			}

			$result[] = [
				'accessCode' => $accessCode,
				'type' => 'department',
				'id' => $this->extractAccessCodeId($accessCode),
				'name' => $name,
			];
			if (count($result) >= $limit)
			{
				break;
			}
		}

		return $result;
	}

	private function findSocialnetworkGroupAccessSubjects(int $userId, string $query, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$result = [];
		$destination = Destination::getRightsDestination($userId);
		foreach (($destination['SONETGROUPS'] ?? []) as $group)
		{
			$groupId = (int)($group['entityId'] ?? $group['id'] ?? 0);
			$accessCode = 'SG' . $groupId . '_K';
			$name = (string)($group['name'] ?? $accessCode);
			if ($groupId <= 0 || !$this->matchesAccessSubjectQuery($query, $accessCode, $name))
			{
				continue;
			}

			$result[] = [
				'accessCode' => $accessCode,
				'type' => 'socialnetwork_group',
				'id' => $groupId,
				'name' => $name,
			];
			if (count($result) >= $limit)
			{
				break;
			}
		}

		return $result;
	}

	private function normalizeDepartmentAccessCode(string $accessCode): string
	{
		$accessCode = mb_strtoupper(trim($accessCode));
		if (preg_match('/^D(\d+)$/', $accessCode, $matches))
		{
			return 'DR' . $matches[1];
		}
		if (preg_match('/^\d+$/', $accessCode))
		{
			return 'DR' . $accessCode;
		}

		return $accessCode;
	}

	private function matchesAccessSubjectQuery(string $query, string $accessCode, string $name): bool
	{
		$query = mb_strtolower(trim($query));
		if ($query === '')
		{
			return false;
		}

		return str_contains(mb_strtolower($accessCode), $query)
			|| str_contains(mb_strtolower($name), $query);
	}

	private function deduplicateAccessSubjects(array $items): array
	{
		$result = [];
		foreach ($items as $item)
		{
			$accessCode = (string)($item['accessCode'] ?? '');
			if ($accessCode === '' || isset($result[$accessCode]))
			{
				continue;
			}

			$result[$accessCode] = $item;
		}

		return array_values($result);
	}

	private function detectAccessSubjectType(string $accessCode): string
	{
		return match (true)
		{
			str_starts_with($accessCode, 'U') || str_starts_with($accessCode, 'IU') => 'user',
			str_starts_with($accessCode, 'DR') => 'department',
			str_starts_with($accessCode, 'SG') => 'socialnetwork_group',
			str_starts_with($accessCode, 'G') => 'group',
			default => 'special',
		};
	}

	private function extractAccessCodeId(string $accessCode): ?int
	{
		if (preg_match('/\d+/', $accessCode, $matches))
		{
			return (int)$matches[0];
		}

		return null;
	}

	private function mapTaskNameToAccessLevel(?string $taskName): ?string
	{
		return match ($taskName)
		{
			RightsManager::TASK_READ => 'read',
			RightsManager::TASK_EDIT => 'edit',
			RightsManager::TASK_FULL => 'full',
			default => $taskName,
		};
	}

	private function buildDeleteWarning(array $fileIds, string $deletionType): ?string
	{
		if (count($fileIds) >= 50)
		{
			return 'Large deletion scope: 50 or more files are selected.';
		}

		if ($deletionType === 'permanent')
		{
			return 'Permanent deletion is irreversible.';
		}

		return null;
	}

	private function formatDateTime(mixed $value): ?string
	{
		return $value instanceof DateTime ? $value->format('c') : null;
	}

	private function formatUserName(?string $name, ?string $lastName): ?string
	{
		$fullName = trim((string)$name . ' ' . (string)$lastName);

		return $fullName !== '' ? $fullName : null;
	}
}
