<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\Destination\DestinationOperation;
use Bitrix\Disk\Destination\DestinationStorageProvider;
use Bitrix\Disk\Destination\DestinationVerdict;
use Bitrix\Disk\Destination\FolderListProvider;
use Bitrix\Disk\Destination\FolderNameValidator;
use Bitrix\Disk\Destination\FolderSearchProvider;
use Bitrix\Disk\Destination\NameConflictPolicy;
use Bitrix\Disk\Destination\ObjectSelection;
use Bitrix\Disk\Destination\OperationExecutor;
use Bitrix\Disk\Destination\StorageTariffRestriction;
use Bitrix\Disk\Destination\VerdictResolver;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\Engine\Controller;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Storage;
use Bitrix\Main\Data\ManagedCache;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectException;

/**
 * Surface of the destination picker: which storages may receive the object, what a folder level looks
 * like, what happens to a chosen pair and the operation itself.
 *
 * A verdict of the read actions is never a permission: the executor checks everything again.
 *
 * Every action but the search takes either one object (objectId) or a selection of them (objectIds), never
 * both (@see resolveSource). The answers of the single form stay as they were; the fields a selection adds
 * live in the answers of a selection alone. The parameters after objectId carry defaults only so that
 * objectId may be left out: a request missing one of them is refused by the very check that weighs it.
 */
class Destination extends Controller
{
	public const ERROR_FEATURE_DISABLED = 'feature_disabled';
	public const ERROR_INVALID_OPERATION = 'invalid_operation';
	public const ERROR_INVALID_QUERY = 'invalid_query';
	public const ERROR_INVALID_CONFLICT_POLICY = 'invalid_conflict_policy';
	public const ERROR_INVALID_SELECTION = 'invalid_selection';
	public const ERROR_ACCESS_DENIED = 'access_denied';
	public const ERROR_OBJECT_GONE = OperationExecutor::ERROR_OBJECT_GONE;
	public const ERROR_FOLDER_GONE = DestinationVerdict::REASON_FOLDER_GONE;
	public const ERROR_STORAGE_GONE = 'storage_gone';
	public const ERROR_STORAGE_RESTRICTED = DestinationVerdict::REASON_STORAGE_RESTRICTED;
	public const ERROR_NO_RIGHTS = DestinationVerdict::REASON_NO_RIGHTS;
	public const ERROR_NO_SPACE = DestinationVerdict::REASON_NO_SPACE;
	public const ERROR_ALREADY_HERE = DestinationVerdict::REASON_ALREADY_HERE;
	public const ERROR_SOURCE_SUBTREE = DestinationVerdict::REASON_SOURCE_SUBTREE;
	public const ERROR_LINK_UNSUPPORTED = DestinationVerdict::REASON_LINK_UNSUPPORTED;
	public const ERROR_NAME_CONFLICT = OperationExecutor::ERROR_NAME_CONFLICT;
	public const ERROR_OPERATION_FAILED = OperationExecutor::ERROR_OPERATION_FAILED;
	public const ERROR_NAME_EMPTY = FolderNameValidator::ERROR_NAME_EMPTY;
	public const ERROR_INVALID_CHARACTERS = FolderNameValidator::ERROR_INVALID_CHARACTERS;
	public const ERROR_NAME_TAKEN = FolderNameValidator::ERROR_NAME_TAKEN;

	private const STATUS_BAD_REQUEST = '400 Bad Request';
	private const STATUS_FORBIDDEN = '403 Forbidden';
	private const STATUS_NOT_FOUND = '404 Not Found';
	private const STATUS_CONFLICT = '409 Conflict';
	private const STATUS_UNPROCESSABLE_ENTITY = '422 Unprocessable Entity';

	/**
	 * Every other denial of the operation is a verdict reason and answers with 403, carrying the reason
	 * itself: the client picks the screen by it.
	 */
	private const OPERATION_STATUSES = [
		self::ERROR_NAME_CONFLICT => self::STATUS_CONFLICT,
		self::ERROR_OPERATION_FAILED => self::STATUS_UNPROCESSABLE_ENTITY,
	];

	/**
	 * A name refused for its characters is not a taken name: the field shows a hint of its own for each.
	 */
	private const NAME_STATUSES = [
		self::ERROR_NAME_TAKEN => self::STATUS_CONFLICT,
		self::ERROR_INVALID_CHARACTERS => self::STATUS_UNPROCESSABLE_ENTITY,
		self::ERROR_NAME_EMPTY => self::STATUS_BAD_REQUEST,
	];

	private const ERROR_MESSAGES = [
		self::ERROR_FEATURE_DISABLED => 'DISK_DESTINATION_ERROR_FEATURE_DISABLED',
		self::ERROR_INVALID_OPERATION => 'DISK_DESTINATION_ERROR_INVALID_OPERATION',
		self::ERROR_INVALID_QUERY => 'DISK_DESTINATION_ERROR_INVALID_QUERY',
		self::ERROR_INVALID_CONFLICT_POLICY => 'DISK_DESTINATION_ERROR_INVALID_CONFLICT_POLICY',
		self::ERROR_INVALID_SELECTION => 'DISK_DESTINATION_ERROR_INVALID_SELECTION',
		self::ERROR_ACCESS_DENIED => 'DISK_DESTINATION_ERROR_ACCESS_DENIED',
		self::ERROR_OBJECT_GONE => 'DISK_DESTINATION_ERROR_OBJECT_GONE',
		self::ERROR_FOLDER_GONE => 'DISK_DESTINATION_ERROR_FOLDER_GONE',
		self::ERROR_STORAGE_GONE => 'DISK_DESTINATION_ERROR_STORAGE_GONE',
		self::ERROR_STORAGE_RESTRICTED => 'DISK_DESTINATION_ERROR_STORAGE_RESTRICTED',
		self::ERROR_NO_RIGHTS => 'DISK_DESTINATION_ERROR_NO_RIGHTS',
		self::ERROR_NO_SPACE => 'DISK_DESTINATION_ERROR_NO_SPACE',
		self::ERROR_ALREADY_HERE => 'DISK_DESTINATION_ERROR_ALREADY_HERE',
		self::ERROR_SOURCE_SUBTREE => 'DISK_DESTINATION_ERROR_SOURCE_SUBTREE',
		self::ERROR_LINK_UNSUPPORTED => 'DISK_DESTINATION_ERROR_LINK_UNSUPPORTED',
		self::ERROR_NAME_CONFLICT => 'DISK_DESTINATION_ERROR_NAME_CONFLICT',
		self::ERROR_OPERATION_FAILED => 'DISK_DESTINATION_ERROR_OPERATION_FAILED',
	];

	private const GROUP_TITLES = [
		DestinationStorageProvider::SECTION_USER => 'DISK_DESTINATION_GROUP_USER',
		DestinationStorageProvider::SECTION_COMMON => 'DISK_DESTINATION_GROUP_COMMON',
		DestinationStorageProvider::SECTION_GROUP => 'DISK_DESTINATION_GROUP_GROUP',
		DestinationStorageProvider::SECTION_COLLAB => 'DISK_DESTINATION_GROUP_COLLAB',
	];

	public function configureActions(): array
	{
		return [
			'getStorages' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\CloseSession(),
				],
			],
			'listFolders' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\CloseSession(),
				],
			],
			'searchFolders' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\CloseSession(),
				],
			],
			'check' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\CloseSession(),
				],
			],
			'apply' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\CloseSession(),
				],
			],
			'createFolder' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\CloseSession(),
				],
			],
		];
	}

	public function getStoragesAction(
		CurrentUser $currentUser,
		int $objectId = 0,
		string $operation = '',
		array $objectIds = [],
	): array|HttpResponse
	{
		if (!Configuration::isDestinationPickerEnabled())
		{
			return $this->createErrorResponse(self::ERROR_FEATURE_DISABLED, self::STATUS_FORBIDDEN);
		}

		$userId = (int)$currentUser->getId();
		if (DestinationOperation::tryFrom($operation) === null)
		{
			return $this->createErrorResponse(self::ERROR_INVALID_OPERATION, self::STATUS_BAD_REQUEST);
		}

		$source = $this->resolveSource($objectId, $objectIds, $userId);
		if ($source instanceof HttpResponse)
		{
			return $source;
		}

		$groups = $this->localizeGroups($this->createStorageProvider()->getGroupedStorages($userId));
		if ($source instanceof ObjectSelection)
		{
			//the popup names the objects it carries, and a descendant of a chosen folder is carried inside it
			return [
				'groups' => $groups,
				'objects' => array_map(
					static fn(BaseObject $object): array => [
						'id' => (int)$object->getId(),
						'name' => (string)$object->getName(),
						'isFolder' => $object instanceof Folder,
					],
					iterator_to_array($source),
				),
			];
		}

		return [
			'groups' => $groups,
			// The object is resolved here anyway; the popup names it in its header and draws its glyph.
			'object' => [
				'isFolder' => $source instanceof Folder,
			],
		];
	}

	/**
	 * The cache of the portal is asked for by this action alone: the levels of one popup weigh one and the
	 * same source, and nothing else of this surface reads a size it may keep.
	 */
	public function listFoldersAction(
		CurrentUser $currentUser,
		ManagedCache $managedCache,
		int $objectId = 0,
		int $folderId = 0,
		string $operation = '',
		array $objectIds = [],
	): array|HttpResponse
	{
		if (!Configuration::isDestinationPickerEnabled())
		{
			return $this->createErrorResponse(self::ERROR_FEATURE_DISABLED, self::STATUS_FORBIDDEN);
		}

		$userId = (int)$currentUser->getId();
		$destinationOperation = DestinationOperation::tryFrom($operation);
		if ($destinationOperation === null)
		{
			return $this->createErrorResponse(self::ERROR_INVALID_OPERATION, self::STATUS_BAD_REQUEST);
		}

		$source = $this->resolveSource($objectId, $objectIds, $userId);
		if ($source instanceof HttpResponse)
		{
			return $source;
		}

		$folder = $this->resolveRealFolder($this->loadFolder($folderId));
		if ($folder === null)
		{
			return $this->createErrorResponse(self::ERROR_FOLDER_GONE, self::STATUS_NOT_FOUND);
		}

		//the level answers for the folder it was opened on, not for the root of the disk that folder lives
		//in: a folder shared out of another personal disk carries rights of its own, and its own canRead()
		//below plus the verdict of every row are the whole authority the level has
		$storage = $folder->getStorage();
		if (!$storage instanceof Storage)
		{
			return $this->createErrorResponse(self::ERROR_STORAGE_GONE, self::STATUS_NOT_FOUND);
		}

		if ($this->createTariffRestriction()->isRestricted($storage))
		{
			return $this->createErrorResponse(self::ERROR_STORAGE_RESTRICTED, self::STATUS_FORBIDDEN);
		}

		if (!$folder->canRead($this->getSecurityContext($storage, $userId)))
		{
			return $this->createErrorResponse(self::ERROR_ACCESS_DENIED, self::STATUS_FORBIDDEN);
		}

		$folderListProvider = $this->createFolderListProvider($userId, $managedCache);

		return [
			'items' => $source instanceof ObjectSelection
				? $folderListProvider->getSelectionFolders($source, $folder, $destinationOperation)
				: $folderListProvider->getFolders($source, $folder, $destinationOperation)
			,
		];
	}

	/**
	 * Folders of the chosen disk the query names, wherever they live under its root. A row of the answer is
	 * a row of the level plus the path it was found at; the checks below are the checks of the level, asked
	 * about the root of the disk the search runs over.
	 */
	public function searchFoldersAction(
		CurrentUser $currentUser,
		int $objectId,
		int $storageId,
		string $operation,
		string $query,
	): array|HttpResponse
	{
		if (!Configuration::isDestinationPickerEnabled())
		{
			return $this->createErrorResponse(self::ERROR_FEATURE_DISABLED, self::STATUS_FORBIDDEN);
		}

		$userId = (int)$currentUser->getId();
		$destinationOperation = DestinationOperation::tryFrom($operation);
		if ($destinationOperation === null)
		{
			return $this->createErrorResponse(self::ERROR_INVALID_OPERATION, self::STATUS_BAD_REQUEST);
		}

		//an empty query is no search: it would answer with the whole disk cut off at the limit
		if (trim($query) === '')
		{
			return $this->createErrorResponse(self::ERROR_INVALID_QUERY, self::STATUS_BAD_REQUEST);
		}

		$object = $this->resolveObject($objectId, $userId);
		if ($object instanceof HttpResponse)
		{
			return $object;
		}

		$storage = $this->loadStorage($storageId);
		$rootObject = $storage?->getRootObject();
		$rootFolder = $rootObject instanceof Folder ? $this->resolveRealFolder($rootObject) : null;
		if ($storage === null || $rootFolder === null)
		{
			return $this->createErrorResponse(self::ERROR_STORAGE_GONE, self::STATUS_NOT_FOUND);
		}

		if ($this->createTariffRestriction()->isRestricted($storage))
		{
			return $this->createErrorResponse(self::ERROR_STORAGE_RESTRICTED, self::STATUS_FORBIDDEN);
		}

		if (!$rootFolder->canRead($this->getSecurityContext($storage, $userId)))
		{
			return $this->createErrorResponse(self::ERROR_ACCESS_DENIED, self::STATUS_FORBIDDEN);
		}

		return [
			'items' => $this->createFolderSearchProvider($userId)->getFolders(
				$object,
				$rootFolder,
				$destinationOperation,
				$query,
			),
		];
	}

	public function checkAction(
		CurrentUser $currentUser,
		int $objectId = 0,
		int $folderId = 0,
		string $operation = '',
		array $objectIds = [],
	): array|HttpResponse
	{
		if (!Configuration::isDestinationPickerEnabled())
		{
			return $this->createErrorResponse(self::ERROR_FEATURE_DISABLED, self::STATUS_FORBIDDEN);
		}

		$userId = (int)$currentUser->getId();
		$destinationOperation = DestinationOperation::tryFrom($operation);
		if ($destinationOperation === null)
		{
			return $this->createErrorResponse(self::ERROR_INVALID_OPERATION, self::STATUS_BAD_REQUEST);
		}

		$source = $this->resolveSource($objectId, $objectIds, $userId);
		if ($source instanceof HttpResponse)
		{
			return $source;
		}

		$folder = $this->resolveRealFolder($this->loadFolder($folderId));
		$storage = $folder?->getStorage();
		if ($folder === null || !$storage instanceof Storage)
		{
			return $this->createErrorResponse(self::ERROR_FOLDER_GONE, self::STATUS_NOT_FOUND);
		}

		if (!$folder->canRead($this->getSecurityContext($storage, $userId)))
		{
			return $this->createErrorResponse(self::ERROR_ACCESS_DENIED, self::STATUS_FORBIDDEN);
		}

		$verdictResolver = $this->createVerdictResolver($userId);
		if ($source instanceof ObjectSelection)
		{
			//a link of a selection is refused by the verdict itself (@see VerdictResolver::resolveSelectionVerdict)
			return [
				'verdict' => $verdictResolver
					->resolveSelectionVerdict($source, $folder, $destinationOperation)
					->toSelectionArray()
				,
			];
		}

		$object = $source;
		$verdict = $verdictResolver->resolveVerdict($object, $folder, $destinationOperation);

		//a refusal the verdict has no reason for: it belongs to the object and travels as an error of the action
		if ($verdict->allowed && $verdictResolver->isOutOfReachAsLink($object, $folder, $destinationOperation))
		{
			return $this->createErrorResponse(self::ERROR_OBJECT_GONE, self::STATUS_FORBIDDEN);
		}

		return [
			'verdict' => $verdict->toArray(),
		];
	}

	public function applyAction(
		CurrentUser $currentUser,
		int $objectId = 0,
		int $folderId = 0,
		string $operation = '',
		string $conflictPolicy = '',
		array $objectIds = [],
	): array|HttpResponse
	{
		if (!Configuration::isDestinationPickerEnabled())
		{
			return $this->createErrorResponse(self::ERROR_FEATURE_DISABLED, self::STATUS_FORBIDDEN);
		}

		$userId = (int)$currentUser->getId();
		$destinationOperation = DestinationOperation::tryFrom($operation);
		if ($destinationOperation === null)
		{
			return $this->createErrorResponse(self::ERROR_INVALID_OPERATION, self::STATUS_BAD_REQUEST);
		}

		$nameConflictPolicy = NameConflictPolicy::tryFrom($conflictPolicy);
		if ($nameConflictPolicy === null)
		{
			return $this->createErrorResponse(self::ERROR_INVALID_CONFLICT_POLICY, self::STATUS_BAD_REQUEST);
		}

		$object = $this->resolveSource($objectId, $objectIds, $userId);
		if ($object instanceof HttpResponse)
		{
			return $object;
		}

		$folder = $this->resolveRealFolder($this->loadFolder($folderId));
		$storage = $folder?->getStorage();
		if ($folder === null || !$storage instanceof Storage)
		{
			return $this->createErrorResponse(self::ERROR_FOLDER_GONE, self::STATUS_NOT_FOUND);
		}

		if (!$folder->canRead($this->getSecurityContext($storage, $userId)))
		{
			return $this->createErrorResponse(self::ERROR_ACCESS_DENIED, self::STATUS_FORBIDDEN);
		}

		if ($object instanceof ObjectSelection)
		{
			return $this->applySelection($object, $folder, $destinationOperation, $nameConflictPolicy, $userId);
		}

		$result = $this->createOperationExecutor($userId)
			->execute($object, $folder, $destinationOperation, $nameConflictPolicy)
		;
		if (!$result->isSuccess())
		{
			$code = (string)$result->getErrors()[0]->getCode();

			return $this->createErrorResponse($code, self::OPERATION_STATUSES[$code] ?? self::STATUS_FORBIDDEN);
		}

		/** @var BaseObject $resultObject */
		$resultObject = $result->getData()['object'];

		return [
			'object' => [
				'id' => (int)$resultObject->getId(),
				'name' => (string)$resultObject->getName(),
				'isFolder' => $resultObject instanceof Folder,
			],
			//a chosen link puts the object into the folder it points at, and that is the destination reported
			'destination' => [
				'id' => (int)$folder->getId(),
				'name' => (string)$folder->getName(),
			],
		];
	}

	/**
	 * Free space is deliberately left unchecked here: a new folder weighs nothing, and both checks of the
	 * domain answer the wrong thing for a zero size - the portal quota refuses it outright, the storage
	 * limit refuses a disk already over its own limit.
	 */
	public function createFolderAction(
		CurrentUser $currentUser,
		int $objectId = 0,
		int $parentFolderId = 0,
		string $name = '',
		string $operation = '',
		array $objectIds = [],
	): array|HttpResponse
	{
		if (!Configuration::isDestinationPickerEnabled())
		{
			return $this->createErrorResponse(self::ERROR_FEATURE_DISABLED, self::STATUS_FORBIDDEN);
		}

		$userId = (int)$currentUser->getId();
		$destinationOperation = DestinationOperation::tryFrom($operation);
		if ($destinationOperation === null)
		{
			return $this->createErrorResponse(self::ERROR_INVALID_OPERATION, self::STATUS_BAD_REQUEST);
		}

		$object = $this->resolveSource($objectId, $objectIds, $userId);
		if ($object instanceof HttpResponse)
		{
			return $object;
		}

		$parentFolder = $this->resolveRealFolder($this->loadFolder($parentFolderId));
		$storage = $parentFolder?->getStorage();
		if ($parentFolder === null || !$storage instanceof Storage)
		{
			return $this->createErrorResponse(self::ERROR_FOLDER_GONE, self::STATUS_NOT_FOUND);
		}

		if ($this->createTariffRestriction()->isRestricted($storage))
		{
			return $this->createErrorResponse(self::ERROR_STORAGE_RESTRICTED, self::STATUS_FORBIDDEN);
		}

		if (!$parentFolder->canAdd($this->getSecurityContext($storage, $userId)))
		{
			return $this->createErrorResponse(self::ERROR_NO_RIGHTS, self::STATUS_FORBIDDEN);
		}

		$validation = $this->createFolderNameValidator()
			->validate($name, (int)$parentFolder->getRealObjectId())
		;
		if (!$validation->isSuccess())
		{
			$error = $validation->getErrors()[0];

			return $this->createErrorResponseFromError(
				$error,
				self::NAME_STATUSES[(string)$error->getCode()] ?? self::STATUS_BAD_REQUEST,
			);
		}

		$folder = $this->addSubFolder($parentFolder, $name, $userId);
		if ($folder === null)
		{
			return $this->createErrorResponse(self::ERROR_OPERATION_FAILED, self::STATUS_UNPROCESSABLE_ENTITY);
		}

		return [
			'folder' => [
				'id' => (int)$folder->getId(),
				'name' => (string)$folder->getName(),
				'hasSubFolders' => $folder->hasSubFolders(),
				'isLink' => $folder->isLink(),
				'verdict' => $object instanceof ObjectSelection
					? $this->createVerdictResolver($userId)
						->resolveSelectionVerdict($object, $folder, $destinationOperation)
						->toSelectionArray()
					: $this->createVerdictResolver($userId)
						->resolveVerdict($object, $folder, $destinationOperation)
						->toArray()
				,
			],
		];
	}

	protected function addSubFolder(Folder $parentFolder, string $name, int $userId): ?Folder
	{
		$folder = $parentFolder->addSubFolder([
			'NAME' => $name,
			'CREATED_BY' => $userId,
		]);

		return $folder instanceof Folder ? $folder : null;
	}

	protected function loadObject(int $objectId): ?BaseObject
	{
		return BaseObject::loadById($objectId, ['STORAGE']);
	}

	/**
	 * @param int[] $objectIds
	 * @return array<int, BaseObject> by id; an id with no row is left out
	 */
	protected function loadObjects(array $objectIds): array
	{
		$objects = [];
		foreach (BaseObject::loadBatchById($objectIds, ['STORAGE']) as $object)
		{
			$objects[(int)$object->getId()] = $object;
		}

		return $objects;
	}

	protected function loadFolder(int $folderId): ?Folder
	{
		return Folder::loadById($folderId, ['STORAGE']);
	}

	protected function loadStorage(int $storageId): ?Storage
	{
		return Storage::loadById($storageId, ['ROOT_OBJECT']);
	}

	/**
	 * A link whose realObjectId points at nothing, or at an object of the wrong kind, has no real folder
	 * at all: both outcomes leave the caller with null.
	 */
	protected function loadRealFolder(Folder $folder): ?Folder
	{
		try
		{
			$realFolder = $folder->getRealObject();
		}
		catch (ObjectException)
		{
			return null;
		}

		return $realFolder instanceof Folder ? $realFolder : null;
	}

	protected function getSecurityContext(Storage $storage, int $userId): SecurityContext
	{
		return $storage->getSecurityContext($userId);
	}

	protected function createStorageProvider(): DestinationStorageProvider
	{
		return new DestinationStorageProvider();
	}

	protected function createFolderListProvider(int $userId, ManagedCache $managedCache): FolderListProvider
	{
		return new FolderListProvider($userId, $managedCache);
	}

	protected function createFolderSearchProvider(int $userId): FolderSearchProvider
	{
		return new FolderSearchProvider($userId);
	}

	protected function createVerdictResolver(int $userId): VerdictResolver
	{
		return new VerdictResolver($userId);
	}

	protected function createTariffRestriction(): StorageTariffRestriction
	{
		return new StorageTariffRestriction();
	}

	protected function createOperationExecutor(int $userId): OperationExecutor
	{
		return new OperationExecutor($userId);
	}

	protected function createFolderNameValidator(): FolderNameValidator
	{
		return new FolderNameValidator();
	}

	/**
	 * An error means that nothing has changed (@see OperationExecutor::executeSelection): it names the object
	 * it belongs to under `objectId`, null for one that belongs to the folder or to the selection as a whole -
	 * a taken name among them. Anything done is a success, and the objects the model refused are reported
	 * apart under `failures` together with the size of the selection: the client tells how many of how many.
	 *
	 * A refusal is told by the phrase of its code, never by the text of the model, which may be technical.
	 */
	private function applySelection(
		ObjectSelection $selection,
		Folder $folder,
		DestinationOperation $operation,
		NameConflictPolicy $conflictPolicy,
		int $userId,
	): array|HttpResponse
	{
		$result = $this->createOperationExecutor($userId)
			->executeSelection($selection, $folder, $operation, $conflictPolicy)
		;
		if (!$result->isSuccess())
		{
			$error = $result->getErrors()[0];
			$code = (string)$error->getCode();

			return $this->createErrorResponse(
				$code,
				self::OPERATION_STATUSES[$code] ?? self::STATUS_FORBIDDEN,
				['objectId' => null, ...($error->getCustomData() ?? [])],
			);
		}

		return [
			'objects' => array_map(
				static fn(BaseObject $resultObject): array => [
					'id' => (int)$resultObject->getId(),
					'name' => (string)$resultObject->getName(),
					'isFolder' => $resultObject instanceof Folder,
				],
				$result->getData()['objects'],
			),
			'destination' => [
				'id' => (int)$folder->getId(),
				'name' => (string)$folder->getName(),
			],
			'total' => count($selection),
			'failures' => array_map(
				static fn(array $failure): array => [
					'objectId' => (int)$failure['object']->getId(),
					'name' => (string)$failure['object']->getName(),
					'code' => $failure['code'],
					'message' => (string)Loc::getMessage(
						self::ERROR_MESSAGES[$failure['code']] ?? self::ERROR_MESSAGES[self::ERROR_OPERATION_FAILED],
					),
				],
				$result->getData()['failures'],
			),
		];
	}

	/**
	 * Exactly one of the two forms: the single id, or a list of them. A request sending both, or neither, is
	 * no request of either form.
	 */
	private function resolveSource(
		int $objectId,
		array $objectIds,
		int $userId,
	): BaseObject|ObjectSelection|HttpResponse
	{
		if (($objectId !== 0) === ($objectIds !== []))
		{
			return $this->createErrorResponse(self::ERROR_INVALID_SELECTION, self::STATUS_BAD_REQUEST);
		}

		return $objectIds === []
			? $this->resolveObject($objectId, $userId)
			: $this->resolveSelection($objectIds, $userId)
		;
	}

	/**
	 * The ids are weighed as they were sent, before anything is loaded: the limit belongs to the request,
	 * not to what is left of it after normalization. Then every object is resolved the way the single one
	 * is, and the first one out of reach ends the request and is named.
	 *
	 * The objects are loaded and their rights read for the whole selection at once; the checks below then
	 * go over the ids in the order they were sent, so the object named is the one the single path would name.
	 */
	private function resolveSelection(array $ids, int $userId): ObjectSelection|HttpResponse
	{
		$objectIds = $this->parseSelectionIds($ids);
		if ($objectIds === null)
		{
			return $this->createErrorResponse(self::ERROR_INVALID_SELECTION, self::STATUS_BAD_REQUEST);
		}

		$loadedObjects = $this->loadObjects($objectIds);
		$this->preloadRights($loadedObjects, $userId);

		$objects = [];
		foreach ($objectIds as $objectId)
		{
			$object = $this->checkObject($loadedObjects[$objectId] ?? null, $userId, ['objectId' => $objectId]);
			if ($object instanceof HttpResponse)
			{
				return $object;
			}

			$objects[] = $object;
		}

		return ObjectSelection::normalized(...$objects);
	}

	/**
	 * Rights of every object of the selection in one go for each storage the selection lives in: the
	 * context of a storage is the one canRead() of its objects asks below (@see getSecurityContext).
	 *
	 * @param array<int, BaseObject> $objects
	 */
	private function preloadRights(array $objects, int $userId): void
	{
		$objectsByStorage = [];
		$storages = [];
		foreach ($objects as $object)
		{
			$storage = $object->getStorage();
			if (!$storage instanceof Storage || $object->isDeleted())
			{
				continue;
			}

			$storageId = (int)$storage->getId();
			$storages[$storageId] = $storage;
			$objectsByStorage[$storageId][] = $object;
		}

		foreach ($objectsByStorage as $storageId => $storageObjects)
		{
			$this->getSecurityContext($storages[$storageId], $userId)->preloadOperationsForObjects($storageObjects);
		}
	}

	/**
	 * @return int[]|null null for anything but MIN_SIZE..MAX_SIZE distinct positive whole numbers
	 */
	private function parseSelectionIds(array $ids): ?array
	{
		if (count($ids) < ObjectSelection::MIN_SIZE || count($ids) > ObjectSelection::MAX_SIZE)
		{
			return null;
		}

		$objectIds = [];
		foreach ($ids as $id)
		{
			$isWholeNumber = is_int($id) || (is_string($id) && preg_match('/^\d+$/', $id) === 1);
			if (!$isWholeNumber || (int)$id <= 0 || isset($objectIds[(int)$id]))
			{
				return null;
			}

			$objectIds[(int)$id] = (int)$id;
		}

		return array_values($objectIds);
	}

	/**
	 * @param array|null $customData what names the object to the client; the single form names nothing
	 */
	private function resolveObject(int $objectId, int $userId, ?array $customData = null): BaseObject|HttpResponse
	{
		return $this->checkObject($this->loadObject($objectId), $userId, $customData);
	}

	/**
	 * What is left of an object once it is loaded, null for one with no row: gone, or out of reach of the user.
	 */
	private function checkObject(?BaseObject $object, int $userId, ?array $customData = null): BaseObject|HttpResponse
	{
		$storage = $object?->getStorage();
		if ($object === null || $object->isDeleted() || !$storage instanceof Storage)
		{
			return $this->createErrorResponse(self::ERROR_OBJECT_GONE, self::STATUS_NOT_FOUND, $customData);
		}

		if (!$object->canRead($this->getSecurityContext($storage, $userId)))
		{
			return $this->createErrorResponse(self::ERROR_ACCESS_DENIED, self::STATUS_FORBIDDEN, $customData);
		}

		return $object;
	}

	/**
	 * The row the tree drew can be a link, and everything past this point belongs to the folder it points
	 * at: its storage answers for the tariff and for the rights, and moveTo(), copyTo() and addSubFolder()
	 * write into its id. A folder that is not a link is its own real folder.
	 *
	 * A destination gone on either side of the link is no destination at all.
	 */
	private function resolveRealFolder(?Folder $folder): ?Folder
	{
		if ($folder === null || $folder->isDeleted())
		{
			return null;
		}

		$realFolder = $this->loadRealFolder($folder);

		return $realFolder === null || $realFolder->isDeleted() ? null : $realFolder;
	}

	private function localizeGroups(array $groups): array
	{
		$localizedGroups = [];
		foreach ($groups as $group)
		{
			$localizedGroups[] = [
				'type' => $group['type'],
				'title' => (string)Loc::getMessage(self::GROUP_TITLES[$group['type']] ?? ''),
				'storages' => $group['storages'],
			];
		}

		return $localizedGroups;
	}

	private function createErrorResponse(string $code, string $status, ?array $customData = null): HttpResponse
	{
		$message = (string)Loc::getMessage(self::ERROR_MESSAGES[$code] ?? '');

		return $this->createErrorResponseFromError(new Error($message, $code, $customData), $status);
	}

	/**
	 * An error the domain has already built keeps its own message: the phrase belongs to the file
	 * the localization resolves it against, not to this controller.
	 */
	private function createErrorResponseFromError(Error $error, string $status): HttpResponse
	{
		return AjaxJson::createError(new ErrorCollection([$error]))->setStatus($status);
	}
}
