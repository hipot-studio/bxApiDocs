<?php
declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk;
use Bitrix\Disk\Controller\DataProviders\ChildrenDataProvider;
use Bitrix\Disk\Controller\ActionFilter\RequiredParameter;
use Bitrix\Disk\Internal\Command\BaseObject\AddSubFolderCommand;
use Bitrix\Disk\Internal\Command\BaseObject\CreateByContentCommand;
use Bitrix\Disk\Internal\Service\UnifiedLink\BaseObjectResolver;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkArchiveService;
use Bitrix\Disk\Internals\Engine\ActionFilter\CheckUnifiedArchiveSignature;
use Bitrix\Disk\Internals\Engine\ActionFilter\CheckReadPermission;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\DirectOnlyCollectionReadAccessChecker;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\UnifiedLinkAccessChecker;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\UnifiedLinkBulkAccessChecker;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessResult;
use Bitrix\Disk\Public\Command\ExternalLink\ValidatePassword\ExternalLinkValidatePasswordCommand;
use Bitrix\Disk\Storage;
use Bitrix\Disk\Type\ObjectCollection;
use Bitrix\Disk\ZipNginx\Archive;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Request;
use Bitrix\Main\SystemException;
use Bitrix\Main\UI\PageNavigation;

class UnifiedLinkActions extends BaseObject
{
	private UnifiedLinkAccessService $unifiedLinkAccessService;
	private UnifiedLinkArchiveService $unifiedLinkArchiveService;
	private Disk\RightsManager $rightsManager;
	private RequiredParameter $requiredParameterFilter;
	private RequiredParameter $requiredParentFolderParameterFilter;
	private array $checkers = [];

	public function __construct(?Request $request = null)
	{
		$serviceLocator = ServiceLocator::getInstance();
		$this->unifiedLinkAccessService = $serviceLocator->get(UnifiedLinkAccessService::class);
		$this->unifiedLinkArchiveService = $serviceLocator->get(UnifiedLinkArchiveService::class);
		$this->rightsManager = new Disk\RightsManager();

		$this->requiredParentFolderParameterFilter = new RequiredParameter(
			argumentName: 'parentFolder',
			return404page: false,
		);

		parent::__construct($request);

		$this->requiredParameterFilter = new RequiredParameter('baseObject', false);
	}

	protected function getDefaultPreFilters(): array
	{
		$defaultPreFilters = parent::getDefaultPreFilters();

		$notCheckReadPermission = static fn($preFilter) => !$preFilter instanceof CheckReadPermission;

		$defaultPreFilters = array_filter($defaultPreFilters, $notCheckReadPermission);

		$defaultPreFilters[] = $this->requiredParameterFilter;

		return $defaultPreFilters;
	}

	protected function getDefaultPostFilters(): array
	{
		$defaultPostFilters = parent::getDefaultPostFilters();

		$defaultPostFilters[] = $this->requiredParameterFilter;

		return $defaultPostFilters;
	}

	public function configureActions(): array
	{
		$configureActions = parent::configureActions();

		$readAccessChecker = new UnifiedLinkAccessChecker(
			targetAccessLevel: UnifiedLinkAccessLevel::Read,
			argumentName: 'baseObject',
			shouldReturn403: false,
		);

		$editAccessChecker = new UnifiedLinkAccessChecker(
			targetAccessLevel: UnifiedLinkAccessLevel::Edit,
			argumentName: 'baseObject',
		);

		$readCollectionAccessChecker = new UnifiedLinkAccessChecker(
			targetAccessLevel: UnifiedLinkAccessLevel::Read,
			argumentName: 'baseObject',
			shouldReturn403: false,
			shouldCheckAll: true,
		);

		$readBulkAccessChecker = new UnifiedLinkBulkAccessChecker(
			targetAccessLevel: UnifiedLinkAccessLevel::Read,
		);

		$readCollectionBulkAccessChecker = new UnifiedLinkBulkAccessChecker(
			targetAccessLevel: UnifiedLinkAccessLevel::Read,
		);

		$directOnlyCollectionReadAccessChecker = new DirectOnlyCollectionReadAccessChecker(
			bulkAccessChecker: $readCollectionBulkAccessChecker,
		);

		$allCheckers = [
			'single' => [
				UnifiedLinkAccessLevel::Read->value => $readAccessChecker,
				UnifiedLinkAccessLevel::Edit->value => $editAccessChecker,
			],
			'singleCheckAllSource' => [
				UnifiedLinkAccessLevel::Read->value => $readCollectionAccessChecker,
			],
			'bulk' => [
				UnifiedLinkAccessLevel::Read->value => $readBulkAccessChecker,
			],
			'bulkCheckAllSource' => [
				UnifiedLinkAccessLevel::Read->value => $readCollectionBulkAccessChecker,
			],
		];

		$actionsToOperations = [
			'single' => [
				'addSubFolder' => Disk\RightsManager::OP_ADD,
				'createFileByContent' => Disk\RightsManager::OP_ADD,
				'getWithRights' => Disk\RightsManager::OP_READ,
				'markDeleted' => Disk\RightsManager::OP_DELETE,
				'copyTo' => Disk\RightsManager::OP_READ,
				'move' => Disk\RightsManager::OP_DELETE,
				'rename' => Disk\RightsManager::OP_EDIT,
				'restore' => Disk\RightsManager::OP_DELETE,
				'restoreFromVersion' => Disk\RightsManager::OP_EDIT,
			],
			'singleCheckAllSource' => [
				'getChildren' => Disk\RightsManager::OP_READ,
			],
			'bulk' => [
				'moveCollection' => Disk\RightsManager::OP_READ,
				// Parent folder is only a list context here; delete permission is checked per object in the action.
				'markDeletedCollection' => Disk\RightsManager::OP_READ,
			],
			'bulkCheckAllSource' => [
				'checkFileLimit' => Disk\RightsManager::OP_READ,
				'getArchiveLink' => Disk\RightsManager::OP_READ,
				'getFolderArchiveLink' => Disk\RightsManager::OP_READ,
				'downloadArchive' => Disk\RightsManager::OP_READ,
				'copyCollection' => Disk\RightsManager::OP_READ,
			],
		];

		$this->checkers = [];

		foreach ($actionsToOperations as $type => $actions)
		{
			foreach ($actions as $action => $right)
			{
				$minLevel = $this->rightsManager->getMinUnifiedLinkAccessLevelByOperation($right);
				$this->checkers[$action] = $allCheckers[$type][$minLevel->value];
			}
		}

		$configureActions['markDeleted'] = [
			'+prefilters' => [
				$this->checkers['markDeleted'],
			],
		];
		$configureActions['rename'] = [
			'+prefilters' => [
				$this->checkers['rename'],
			],
		];
		$configureActions['copyTo'] = [
			'+prefilters' => [
				$this->checkers['copyTo'],
			],
		];
		$configureActions['move'] = [
			'+prefilters' => [
				$this->checkers['move'],
			],
		];
		$configureActions['getExternalLink'] = [
			'+prefilters' => [
				$editAccessChecker,
			],
		];
		$configureActions['generateExternalLink'] = [
			'+prefilters' => [
				$editAccessChecker,
			],
		];
		$configureActions['disableExternalLink'] = [
			'+prefilters' => [
				$editAccessChecker,
			],
		];
		$configureActions['get'] = [
			'+prefilters' => [
				$readAccessChecker,
			],
		];
		$configureActions['getWithRights'] = [
			'+prefilters' => [
				$this->checkers['getWithRights'],
			],
		];
		$configureActions['getChildren'] = [
			'+prefilters' => [
				$this->checkers['getChildren'],
			],
		];
		$configureActions['addSubFolder'] = [
			'+prefilters' => [
				$this->checkers['addSubFolder'],
			],
		];
		$configureActions['createFileByContent'] = [
			'+prefilters' => [
				$this->checkers['createFileByContent'],
			],
		];
		$configureActions['checkFileLimit'] = [
			'-prefilters' => [
				RequiredParameter::class,
			],
			'+prefilters' => [
				$this->requiredParentFolderParameterFilter,
				$this->checkers['checkFileLimit'],
				$directOnlyCollectionReadAccessChecker,
			],
		];
		$configureActions['getArchiveLink'] = [
			'-prefilters' => [
				RequiredParameter::class,
			],
			'+prefilters' => [
				$this->requiredParentFolderParameterFilter,
				$this->checkers['getArchiveLink'],
			],
		];
		$configureActions['getFolderArchiveLink'] = [
			'-prefilters' => [
				RequiredParameter::class,
			],
			'+prefilters' => [
				$this->requiredParentFolderParameterFilter,
				$this->checkers['getFolderArchiveLink'],
			],
		];
		$configureActions['downloadArchive'] = [
			'-prefilters' => [
				RequiredParameter::class,
				ActionFilter\Csrf::class,
				ActionFilter\Authentication::class,
			],
			'+prefilters' => [
				new ActionFilter\Authentication(true),
				new Disk\Internals\Engine\ActionFilter\HumanReadableError(),
				$this->requiredParentFolderParameterFilter,
				$this->checkers['downloadArchive'],
				$directOnlyCollectionReadAccessChecker,
				new CheckUnifiedArchiveSignature(),
				new ActionFilter\CloseSession(),
			],
		];
		$configureActions['markDeletedCollection'] = [
			'-prefilters' => [
				RequiredParameter::class,
			],
			'+prefilters' => [
				$this->requiredParentFolderParameterFilter,
				$this->checkers['markDeletedCollection'],
			],
		];
		$configureActions['copyCollection'] = [
			'-prefilters' => [
				RequiredParameter::class,
			],
			'+prefilters' => [
				$this->requiredParentFolderParameterFilter,
				$this->checkers['copyCollection'],
				$directOnlyCollectionReadAccessChecker,
			],
		];
		$configureActions['moveCollection'] = [
			'-prefilters' => [
				RequiredParameter::class,
			],
			'+prefilters' => [
				$this->requiredParentFolderParameterFilter,
				$this->checkers['moveCollection'],
			],
		];
		$configureActions['restoreCollection'] = [
			'-prefilters' => [
				RequiredParameter::class,
			],
		];

		return $configureActions;
	}

	/**
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				Disk\BaseObject::class,
				'baseObject',
				function ($className, string $uniqueCode): ?Disk\BaseObject {
					return Disk\BaseObject::loadByUniqueCode($uniqueCode);
				},
			),
			new ExactParameter(
				Disk\Folder::class,
				'parentFolder',
				function ($className, string $uniqueCode): ?Disk\Folder {
					return Disk\Folder::loadByUniqueCode($uniqueCode);
				},
			),
			new ExactParameter(
				Disk\Type\ObjectCollection::class,
				'unifiedObjectCollection',
				function ($className, array $uniqueCodes): Disk\Type\ObjectCollection {
					return $this->resolveUnifiedObjectCollection($uniqueCodes);
				},
			),
			new ExactParameter(
				Disk\Storage::class,
				'storage',
				function ($className, int $storageId): ?Disk\Storage {
					return Disk\Storage::loadById($storageId);
				},
			),
		];
	}

	/**
	 * @throws BinderArgumentException
	 */
	protected function resolveUnifiedObjectCollection(array $uniqueCodes): Disk\Type\ObjectCollection
	{
		$normalizedUniqueCodes = [];

		foreach ($uniqueCodes as $uniqueCode)
		{
			if (!is_string($uniqueCode) || $uniqueCode === '')
			{
				throw new BinderArgumentException(
					'Invalid unique code in unified object collection.',
					'unifiedObjectCollection',
					[
						new Error(
							'Invalid unique code in unified object collection.',
							'INVALID_UNIFIED_OBJECT_COLLECTION',
						),
					],
				);
			}

			$normalizedUniqueCodes[$uniqueCode] = $uniqueCode;
		}

		$unifiedObjectCollection = Disk\Type\ObjectCollection::createByUniqueCodes(...array_values($normalizedUniqueCodes));

		if (count($unifiedObjectCollection->toArray()) !== count($normalizedUniqueCodes))
		{
			throw new BinderArgumentException(
				'Could not resolve unified object collection.',
				'unifiedObjectCollection',
				[
					new Error(
						'Could not resolve unified object collection.',
						'COULD_NOT_RESOLVE_UNIFIED_OBJECT_COLLECTION',
					),
				],
			);
		}

		return $unifiedObjectCollection;
	}

	/**
	 * @param Disk\Folder|null $parentFolder need here for check permissions
	 * @param Disk\Type\ObjectCollection $unifiedObjectCollection
	 * @param CurrentUser $currentUser
	 * @return array|null
	 */
	public function checkFileLimitAction(
		?Disk\Folder $parentFolder,
		Disk\Type\ObjectCollection $unifiedObjectCollection,
		CurrentUser $currentUser,
	): ?array
	{
		$shouldCheckUnifiedLink = $this->shouldCheckUnifiedLinkForCurrentCollectionAction();

		if (!is_bool($shouldCheckUnifiedLink))
		{
			return null;
		}

		return [
			'isFileLimitExceeded' => $this->unifiedLinkArchiveService->isFileLimitExceeded(
				objectCollection: $unifiedObjectCollection,
				userId: (int)$currentUser->getId(),
				shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
			),
		];
	}

	/**
	 * @param Disk\Folder|null $parentFolder need here for check permissions
	 * @param Disk\Type\ObjectCollection $unifiedObjectCollection
	 * @return array
	 * @throws ArgumentTypeException
	 */
	public function getArchiveLinkAction(
		?Disk\Folder $parentFolder,
		Disk\Type\ObjectCollection $unifiedObjectCollection,
	): array
	{
		$shouldCheckUnifiedLink = $this->shouldCheckUnifiedLinkForCurrentCollectionAction();

		if (
			!is_bool($shouldCheckUnifiedLink)
			|| !$this->canDownloadArchiveObjects(
				parentFolder: $parentFolder,
				unifiedObjectCollection: $unifiedObjectCollection,
				currentUser: CurrentUser::get(),
				shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
				shouldCheckDescendants: false,
			)
		)
		{
			return [];
		}

		$uri = $this->getActionUri(
			actionName: 'downloadArchive',
			params: $this->unifiedLinkArchiveService->getSignedArchiveParameters(
				$parentFolder,
				$unifiedObjectCollection,
			),
		);

		return [
			'downloadArchiveUri' => $uri,
		];
	}

	/**
	 * @param Disk\Folder|null $parentFolder need here for check permissions
	 * @param Disk\Type\ObjectCollection $unifiedObjectCollection
	 * @return array|null
	 * @throws ArgumentTypeException
	 */
	public function getFolderArchiveLinkAction(
		?Disk\Folder $parentFolder,
		Disk\Type\ObjectCollection $unifiedObjectCollection,
	): ?array
	{
		$shouldCheckUnifiedLink = $this->shouldCheckUnifiedLinkForCurrentCollectionAction();

		if (!is_bool($shouldCheckUnifiedLink))
		{
			return null;
		}

		$objects = $unifiedObjectCollection->toArray();
		$object = $objects[0] ?? null;

		if (count($objects) !== 1 || !$object instanceof Disk\Folder)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		if (!$this->canDownloadArchiveObjects(
			parentFolder: $parentFolder,
			unifiedObjectCollection: $unifiedObjectCollection,
			currentUser: CurrentUser::get(),
			shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
			shouldCheckDescendants: false,
		))
		{
			return null;
		}

		$uri = $this->getActionUri(
			actionName: 'downloadArchive',
			params: $this->unifiedLinkArchiveService->getSignedArchiveParameters(
				parentFolder: $parentFolder,
				objectCollection: $unifiedObjectCollection,
				skipRootFolder: true,
			),
		);

		return [
			'downloadArchiveUri' => $uri,
		];
	}

	/**
	 * @param Disk\Folder|null $parentFolder need here for check permissions
	 * @param Disk\Type\ObjectCollection $unifiedObjectCollection
	 * @param CurrentUser $currentUser
	 * @param bool $skipRootFolder
	 * @return Archive|null
	 */
	public function downloadArchiveAction(
		?Disk\Folder $parentFolder,
		Disk\Type\ObjectCollection $unifiedObjectCollection,
		CurrentUser $currentUser,
		bool $skipRootFolder = false,
	): ?Archive
	{
		$shouldCheckUnifiedLink = $this->shouldCheckUnifiedLinkForCurrentCollectionAction();

		if (!is_bool($shouldCheckUnifiedLink))
		{
			return null;
		}

		if (!$this->canDownloadArchiveObjects(
			parentFolder: $parentFolder,
			unifiedObjectCollection: $unifiedObjectCollection,
			currentUser: $currentUser,
			shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
		))
		{
			return null;
		}

		return $this->unifiedLinkArchiveService->createArchive(
			objectCollection: $unifiedObjectCollection,
			userId: (int)$currentUser->getId(),
			skipRootFolder: $skipRootFolder,
			shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
		);
	}

	/**
	 * @param Disk\Folder|null $parentFolder need here for check permissions
	 * @param Disk\Type\ObjectCollection $unifiedObjectCollection
	 * @return array
	 */
	public function markDeletedCollectionAction(
		?Disk\Folder $parentFolder,
		Disk\Type\ObjectCollection $unifiedObjectCollection,
	): array
	{
		$currentUserId = (int)$this->getCurrentUser()->getId();
		$shouldCheckUnifiedLink = $this->shouldCheckUnifiedLinkForCurrentCollectionAction();

		if (!is_bool($shouldCheckUnifiedLink))
		{
			return [
				'markedDeletedObjectIds' => [],
			];
		}

		$securityContext = $this->preloadUnifiedLinkAccessForCollection(
			parentFolder: $parentFolder,
			unifiedObjectCollection: $unifiedObjectCollection,
			userId: $currentUserId,
			shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
		);

		if (!$securityContext)
		{
			return [
				'markedDeletedObjectIds' => [],
			];
		}

		$markedDeletedObjectIds = [];

		foreach ($unifiedObjectCollection as $object)
		{
			if ($object->isDeleted())
			{
				continue;
			}

			if (!$object->canMarkDeleted($securityContext))
			{
				$this->errorCollection[] = new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED'));

				continue;
			}

			if (!$object->markDeleted($currentUserId))
			{
				$this->errorCollection->add($object->getErrors());

				continue;
			}

			$markedDeletedObjectIds[] = $object->getId();
		}

		return [
			'markedDeletedObjectIds' => $markedDeletedObjectIds,
		];
	}

	protected function preloadUnifiedLinkAccessForCollection(
		?Disk\Folder $parentFolder,
		Disk\Type\ObjectCollection $unifiedObjectCollection,
		int $userId,
		bool $shouldCheckUnifiedLink,
		bool $shouldPreloadDownloadAccess = false,
	): ?Disk\Security\SecurityContext
	{
		if (!$parentFolder)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$storage = $parentFolder->getStorage();

		if (!$storage)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$securityContext = $storage->getSecurityContext(
			user: $userId,
			shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
		);

		$parentFolder->preloadOperationsForSpecifiedObjects(
			ids: $unifiedObjectCollection->getIds(),
			securityContext: $securityContext,
			shouldPreloadDownloadAccess: $shouldPreloadDownloadAccess,
		);

		return $securityContext;
	}

	/**
	 * @param Disk\Folder|null $parentFolder need here for check permissions
	 * @param Disk\Type\ObjectCollection $unifiedObjectCollection
	 * @param int $targetFolderId
	 * @param CurrentUser $currentUser
	 * @return array|null
	 * @throws SystemException
	 */
	public function copyCollectionAction(
		?Disk\Folder $parentFolder,
		Disk\Type\ObjectCollection $unifiedObjectCollection,
		int $targetFolderId,
		CurrentUser $currentUser,
	): ?array
	{
		$targetFolder = $this->getTargetFolder($targetFolderId, $currentUser);

		if (!$targetFolder)
		{
			return null;
		}

		$copiedObjectIds = [];
		$userId = (int)$currentUser->getId();

		foreach ($unifiedObjectCollection as $object)
		{
			$copiedObject = $this->copyObjectToFolder($object, $targetFolder, $userId);

			if ($copiedObject)
			{
				$copiedObjectIds[] = $copiedObject->getId();
			}
		}

		return [
			'copiedObjectIds' => $copiedObjectIds,
		];
	}

	public function copyToAction(
		?Disk\BaseObject $baseObject,
		int $targetFolderId,
		CurrentUser $currentUser,
	): ?array
	{
		if (!$baseObject)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$userId = (int)$currentUser->getId();
		if (!$this->canCopyTo($baseObject, $userId))
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$targetFolder = $this->getTargetFolder($targetFolderId, $currentUser);

		if (!$targetFolder)
		{
			return null;
		}

		$copiedObject = $this->copyObjectToFolder($baseObject, $targetFolder, $userId);

		if (!$copiedObject)
		{
			return null;
		}

		return $this->get($copiedObject);
	}

	private function canCopyTo(Disk\BaseObject $baseObject, int $userId): bool
	{
		$securityContext = $baseObject->getStorage()?->getSecurityContext(
			user: $userId,
			shouldCheckUnifiedLink: true,
		);

		return $securityContext instanceof Disk\Security\SecurityContext
			&& $baseObject->canDownload($securityContext);
	}

	/**
	 * @param Disk\Folder|null $parentFolder need here for check permissions
	 * @param Disk\Type\ObjectCollection $unifiedObjectCollection
	 * @param int $targetFolderId
	 * @param CurrentUser $currentUser
	 * @return array|null
	 */
	public function moveCollectionAction(
		?Disk\Folder $parentFolder,
		Disk\Type\ObjectCollection $unifiedObjectCollection,
		int $targetFolderId,
		CurrentUser $currentUser,
	): ?array
	{
		$targetFolder = $this->getTargetFolder(
			targetFolderId: $targetFolderId,
			currentUser: $currentUser,
			shouldCheckCommonStorage: true,
		);

		if (!$targetFolder)
		{
			return null;
		}

		$movedObjectIds = [];
		$skippedObjectIds = [];
		$delayedErrors = [];
		$userId = (int)$currentUser->getId();
		$shouldCheckUnifiedLink = $this->shouldCheckUnifiedLinkForCurrentCollectionAction();

		if (!is_bool($shouldCheckUnifiedLink))
		{
			return null;
		}

		$securityContext = $this->preloadUnifiedLinkAccessForCollection(
			parentFolder: $parentFolder,
			unifiedObjectCollection: $unifiedObjectCollection,
			userId: $userId,
			shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
		);

		if (!$securityContext)
		{
			return null;
		}

		foreach ($unifiedObjectCollection as $object)
		{
			if (!$object->canMove($securityContext, $targetFolder))
			{
				$skippedObjectIds[] = $object->getId();
				$delayedErrors[] = new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED'));

				continue;
			}

			$movedObject = $object->moveTo($targetFolder, $userId, true);

			if (!$movedObject)
			{
				$skippedObjectIds[] = $object->getId();
				foreach ($object->getErrors() as $error)
				{
					$delayedErrors[] = $error;
				}

				continue;
			}

			$movedObjectIds[] = $movedObject->getId();
		}

		if (empty($movedObjectIds))
		{
			foreach ($delayedErrors as $error)
			{
				$this->addError($error);
			}
		}

		return [
			'movedObjectIds' => $movedObjectIds,
			'skippedObjectIds' => $skippedObjectIds,
		];
	}

	public function moveAction(
		?Disk\BaseObject $baseObject,
		int $targetFolderId,
		CurrentUser $currentUser,
	): ?array
	{
		if (!$baseObject)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$targetFolder = $this->getTargetFolder(
			targetFolderId: $targetFolderId,
			currentUser: $currentUser,
			shouldCheckCommonStorage: true,
		);

		if (!$targetFolder)
		{
			return null;
		}

		$userId = (int)$currentUser->getId();

		$securityContext = $baseObject->getStorage()?->getSecurityContext(
			user: $userId,
			shouldCheckUnifiedLink: true,
		);

		if (!$securityContext || !$baseObject->canMove($securityContext, $targetFolder))
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$movedObject = $baseObject->moveTo($targetFolder, $userId, true);

		if (!$movedObject)
		{
			$this->errorCollection->add($baseObject->getErrors());

			return null;
		}

		return $this->get($movedObject);
	}

	protected function getTargetFolder(
		int $targetFolderId,
		CurrentUser $currentUser,
		bool $shouldCheckCommonStorage = false,
	): ?Disk\Folder
	{
		$targetFolder = Disk\Folder::loadById($targetFolderId, ['STORAGE']);

		if (!$targetFolder)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$targetStorage = $targetFolder->getStorage();

		if (!$targetStorage)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		if ($shouldCheckCommonStorage && $this->shouldBeBlockMove($targetStorage))
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$securityContext = $targetStorage->getSecurityContext((int)$currentUser->getId());

		if (!$targetFolder->canAdd($securityContext))
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		return $targetFolder;
	}

	protected function shouldBeBlockMove(Disk\Storage $storage): bool
	{
		$proxyType = $storage->getProxyType();

		if (!($proxyType instanceof Disk\ProxyType\Common))
		{
			return false;
		}

		return !Disk\Integration\Bitrix24Manager::isFeatureEnabled('disk_common_storage');
	}

	protected function copyObjectToFolder(
		Disk\BaseObject $object,
		Disk\Folder $targetFolder,
		int $userId,
	): ?Disk\BaseObject
	{
		if ($object->isLink())
		{
			$newCopy = $object->getRealObject()->copyTo($targetFolder, $userId, true);

			$newCopy?->rename($object->getName(), true);
		}
		else
		{
			$newCopy = $object->copyTo($targetFolder, $userId, true);
		}

		if (!$newCopy)
		{
			$this->errorCollection->add($object->getErrors());

			return null;
		}

		return $newCopy;
	}

	/**
	 * @param Disk\BaseObject|null $baseObject
	 * @return void
	 */
	public function markDeletedAction(?Disk\BaseObject $baseObject): void
	{
		$this->markDeleted($baseObject, true);
	}

	public function getChildrenAction(
		?Disk\BaseObject $baseObject,
		CurrentUser $currentUser,
		string $search = null,
		string $searchScope = 'currentFolder',
		bool $showRights = true,
		array $context = [],
		array $order = [],
		PageNavigation $pageNavigation = null,
	): ?array
	{
		if (!$baseObject instanceof Disk\Folder)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$shouldCheckUnifiedLink = $this->shouldCheckUnifiedLinkForCurrentCollectionAction();

		if (!is_bool($shouldCheckUnifiedLink))
		{
			return null;
		}

		$childrenDataProvider = new ChildrenDataProvider();

		$result = $childrenDataProvider->getChildren(
			folder: $baseObject,
			currentUser: $currentUser,
			search: $search,
			searchScope: $searchScope,
			showRights: $showRights,
			context: $context,
			order: $order,
			pageNavigation: $pageNavigation,
			shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
		);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$data = $result->getData();

		$response = [
			'folderId' => (int)$baseObject->getId(),
			'items' => $data['children'] ?? [],
			'total' => $data['total'] ?? 0,
		];

		if ($showRights)
		{
			$rights = $this->getCurrentFolderRights($baseObject, $currentUser, $shouldCheckUnifiedLink);

			if ($rights === null)
			{
				return null;
			}

			$response['currentFolderRights'] = $rights;
		}

		return $response;
	}

	protected function shouldCheckUnifiedLinkForCurrentCollectionAction(): ?bool
	{
		$checker = $this->checkers[$this->getCurrentAction()->getName()] ?? null;

		if (!$checker instanceof UnifiedLinkAccessChecker && !$checker instanceof UnifiedLinkBulkAccessChecker)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$accessResult = $checker->getAccessResult();

		if (!$accessResult instanceof UnifiedLinkAccessResult)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		return !$accessResult->isOnlyPermissionSystemAccess();
	}

	protected function canDownloadArchiveObjects(
		?Disk\Folder $parentFolder,
		Disk\Type\ObjectCollection $unifiedObjectCollection,
		CurrentUser $currentUser,
		bool $shouldCheckUnifiedLink,
		bool $shouldCheckDescendants = true,
	): bool
	{
		$securityContext = $this->preloadUnifiedLinkAccessForCollection(
			parentFolder: $parentFolder,
			unifiedObjectCollection: $unifiedObjectCollection,
			userId: (int)$currentUser->getId(),
			shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
			shouldPreloadDownloadAccess: true,
		);

		if (!$securityContext)
		{
			return false;
		}

		if (!$this->getArchiveDownloadAccessChecker()->canDownloadObjects(
			objects: $unifiedObjectCollection->toArray(),
			securityContextResolver: static fn(Disk\BaseObject $object) => $securityContext,
			shouldCheckDescendants: $shouldCheckDescendants,
			shouldUseRightsCheck: !$shouldCheckUnifiedLink,
			shouldFollowFolderLinks: !$shouldCheckUnifiedLink,
			shouldPreloadSelectedObjects: false,
		))
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return false;
		}

		return true;
	}

	protected function getArchiveDownloadAccessChecker(): Disk\Internal\Service\Archive\ArchiveDownloadAccessChecker
	{
		return new Disk\Internal\Service\Archive\ArchiveDownloadAccessChecker();
	}

	public function addSubFolderAction(
		?Disk\BaseObject $baseObject,
		CurrentUser $currentUser,
		string $name,
		bool $generateUniqueName = false,
	): ?array
	{
		if (!$baseObject instanceof Disk\Folder)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$result = (new AddSubFolderCommand(
			folder: $baseObject,
			name: $name,
			createdBy: (int)$currentUser->getId(),
			generateUniqueName: $generateUniqueName,
			shouldCheckUnifiedLink: true,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$subFolder = $result->getData()['folder'];

		return $this->get($subFolder);
	}

	public function getWithRightsAction(?Disk\BaseObject $baseObject, CurrentUser $currentUser): ?array
	{
		if (!$baseObject)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$storage = $baseObject->getStorage();

		if (!$storage)
		{
			$this->addError(new Error('Could not find storage for object.'));

			return null;
		}

		$securityContext = $storage->getSecurityContext(
			user: $currentUser->getId(),
			shouldCheckUnifiedLink: true,
		);

		$diskObject = $baseObject->jsonSerialize();
		$diskObject['rights'] = $this->rightsManager->getAvailableActions($baseObject, $securityContext);
		$diskObject['links']['external'] = $this->getExternalLink($baseObject);

		return [
			'diskObject' => $diskObject,
		];
	}

	/**
	 * @param Disk\BaseObject|null $baseObject
	 * @param CurrentUser $currentUser
	 * @param string $filename
	 * @param Disk\Bitrix24Disk\TmpFile $content
	 * @param bool $generateUniqueName
	 * @return array|null
	 * @throws CommandException
	 * @throws CommandValidationException
	 */
	public function createFileByContentAction(
		?Disk\BaseObject $baseObject,
		CurrentUser $currentUser,
		string $filename,
		Disk\Bitrix24Disk\TmpFile $content,
		bool $generateUniqueName = false,
	): ?array
	{
		if (!$baseObject instanceof Disk\Folder)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$contentType = $this->request->getHeader('X-Upload-Content-Type')?: $content->getContentType();
		$previewFileData = $this->request->getFile('previewFile');

		$result = (new CreateByContentCommand(
			folder: $baseObject,
			filename: $filename,
			content: $content,
			createdBy: (int)$currentUser->getId(),
			contentType: $contentType,
			generateUniqueName: $generateUniqueName,
			shouldCheckUnifiedLink: true,
			previewFileData: is_array($previewFileData) ? $previewFileData : null,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$file = $result->getData()['file'];

		return (new File($this->request))->getAction($file);
	}

	private function getCurrentFolderRights(
		Disk\Folder $folder,
		CurrentUser $currentUser,
		bool $shouldCheckUnifiedLink = true,
	): ?array
	{
		$storage = $folder->getStorage();

		if (!$storage)
		{
			$this->addError(new Error('Could not find storage for folder.'));

			return null;
		}

		$securityContext = $storage->getSecurityContext($currentUser->getId(), $shouldCheckUnifiedLink);

		return Disk\Driver::getInstance()->getRightsManager()->getAvailableActions($folder, $securityContext);
	}

	/**
	 * @param Storage|null $storage
	 * @param string $trashUls
	 * @param ObjectCollection $unifiedObjectCollection
	 * @return array|null
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 */
	public function restoreCollectionAction(
		?Disk\Storage $storage,
		string $trashUls,
		Disk\Type\ObjectCollection $unifiedObjectCollection,
	): ?array
	{
		/** @var array<int, Disk\BaseObject> $objects */
		$objects = $unifiedObjectCollection->toArray();

		if (!$storage || empty($objects))
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		foreach ($objects as $object)
		{
			if ((int)$object->getStorageId() !== (int)$storage->getId() || !$object->isDeleted())
			{
				$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

				return null;
			}
		}

		if (!$this->unifiedLinkAccessService->validateTrashUlsForObjectId($storage->getRootObjectId(), $trashUls))
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$securityContext = $storage->getCurrentUserSecurityContext(true);

		if ($securityContext instanceof Disk\Security\DiskSecurityContext)
		{
			$securityContext->preloadUnifiedLinkAccessLevelsForObjects($objects);
		}

		foreach ($objects as $object)
		{
			if (!$object->canRestore($securityContext))
			{
				$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

				return null;
			}
		}

		$restoredIds = [];
		$currentUserId = $this->getCurrentUser()->getId();

		foreach ($objects as $object)
		{
			if (!$object->restore($currentUserId))
			{
				$this->errorCollection->add($object->getErrors());

				continue;
			}

			$restoredIds[] = $object->getRealObjectId();
		}

		return [
			'restoredObjectIds' => $restoredIds,
		];
	}

	/**
	 * @param Disk\BaseObject|null $baseObject
	 * @param string $newName
	 * @param bool $autoCorrect
	 * @param bool $generateUniqueName
	 * @return array|null
	 */
	public function renameAction(
		?Disk\BaseObject $baseObject,
		string $newName,
		bool $autoCorrect = false,
		bool $generateUniqueName = false,
	): ?array
	{
		return $this->rename($baseObject, $newName, $autoCorrect, $generateUniqueName, true);
	}

	/**
	 * @param Disk\BaseObject|null $baseObject
	 * @return array|null
	 * @throws ArgumentTypeException
	 */
	public function restoreAction(?Disk\BaseObject $baseObject): ?array
	{
		$response = $this->restore($baseObject, true);
		$restoredObjectId = (int)($response['object']['id'] ?? 0);

		if ($restoredObjectId > 0)
		{
			$response['object']['uls'] = $this->unifiedLinkAccessService->getUlsForObjectId($restoredObjectId);
		}

		return $response;
	}

	/**
	 * @param Disk\BaseObject|null $baseObject
	 * @param Disk\Version|null $version
	 * @return array|null
	 */
	public function restoreFromVersionAction(?Disk\BaseObject $baseObject, ?Disk\Version $version): ?array
	{
		if (!$baseObject instanceof Disk\File || !$version instanceof Disk\Version)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$securityContext = $baseObject->getStorage()->getCurrentUserSecurityContext(true);
		if (!$baseObject->canManageVersions($securityContext))
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return null;
		}

		if (!$baseObject->restoreFromVersion($version, $this->getCurrentUser()->getId()))
		{
			$this->addErrors($baseObject->getErrors());

			return null;
		}

		return $this->get($baseObject);
	}

	public function deleteVersionAction(?Disk\BaseObject $baseObject, ?Disk\Version $version): void
	{
		if (
			!$baseObject instanceof Disk\File
			|| !$version instanceof Disk\Version
			|| BaseObjectResolver::resolveVersion($baseObject, $version) !== $version
		)
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return;
		}

		$securityContext = $baseObject->getStorage()->getCurrentUserSecurityContext(true);
		if (!$baseObject->canManageVersions($securityContext))
		{
			$this->addError(new Error(Loc::getMessage('DISK_CONTROLLER_UNIFIED_LINK_ACTIONS_ERROR_MESSAGE_DENIED')));

			return;
		}

		if (!$version->delete($this->getCurrentUser()->getId()))
		{
			$this->addErrors($version->getErrors());
		}
	}

	/**
	 * @param Disk\BaseObject|null $baseObject uniqueCode is passed as string and converted to BaseObject object via autowiring
	 * @return array|null
	 */
	public function getExternalLinkAction(?Disk\BaseObject $baseObject): ?array
	{
		/** @noinspection NullPointerExceptionInspection */
		return $this->getExternalLink($baseObject);
	}

	/**
	 * @param Disk\BaseObject|null $baseObject uniqueCode is passed as string and converted to BaseObject object via autowiring
	 * @return array|null
	 */
	public function generateExternalLinkAction(?Disk\BaseObject $baseObject): ?array
	{
		/** @noinspection NullPointerExceptionInspection */
		return $this->generateExternalLink($baseObject);
	}

	/**
	 * @param Disk\BaseObject|null $baseObject uniqueCode is passed as string and converted to BaseObject object via autowiring
	 * @return bool
	 */
	public function disableExternalLinkAction(?Disk\BaseObject $baseObject): bool
	{
		/** @noinspection NullPointerExceptionInspection */
		return $this->disableExternalLink($baseObject);
	}

	/**
	 * @param Disk\BaseObject|null $baseObject uniqueCode is passed as string and converted to BaseObject object via autowiring
	 * @param Disk\Version|null $version versionId is passed as int and converted to Version object via autowiring
	 * @return array|null
	 * @throws CommandException
	 * @throws CommandValidationException
	 */
	public function validatePasswordAction(?Disk\BaseObject $baseObject, ?Disk\Version $version = null): ?array
	{
		$resolvedObject = BaseObjectResolver::resolve($baseObject, $version);
		$password = $this->request->get('password');

		$validatePasswordCommand = new ExternalLinkValidatePasswordCommand(
			objectId: $resolvedObject->getId(),
			password: $password,
			sourceIp: trim((string)$this->request->getRemoteAddress()),
			captchaSid: $this->request->get('captchaSid'),
			captchaWord: $this->request->get('captchaWord'),
		);

		$validatePasswordResult = $validatePasswordCommand->run();
		$validatePasswordError = $validatePasswordResult->getError();

		if (!$validatePasswordResult->isSuccess() && $validatePasswordError instanceof Error)
		{
			$this->addError($validatePasswordError);

			return null;
		}

		return $this->get($resolvedObject);
	}

	/**
	 * @param Disk\BaseObject $baseObject uniqueCode is passed as string and converted to BaseObject object via autowiring
	 * @param Disk\Version|null $version versionId is passed as int and converted to Version object via autowiring
	 * @param Disk\AttachedObject|null $attachedObject attachedObjectId is passed as int and converted to AttachedObject
	 * object via autowiring, needed to check access to the base object, don't remove it
	 * @return array
	 */
	public function getAction(
		Disk\BaseObject $baseObject,
		?Disk\Version $version = null,
		?Disk\AttachedObject $attachedObject = null,
	): array
	{
		$resolvedObject = Disk\Internal\Service\UnifiedLink\BaseObjectResolver::resolve($baseObject, $version);

		return $this->get($resolvedObject);
	}
}
