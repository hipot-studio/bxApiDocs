<?php

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Controller\ActionFilter\RequiredParameter;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Document\OnlyOffice\Service\OnlyOfficeSessionTerminationService;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\UnifiedLinkAccessChecker;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internal\Command\UnifiedLink\SetAccessCommand;
use Bitrix\Disk\Internal\Enum\ExternalLinkDisableReason;
use Bitrix\Disk\Internal\Enum\MembersAccessDisableReason;
use Bitrix\Disk\Internal\Enum\SharingMemberType;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Disk\File;
use \Bitrix\Disk\Folder;
use Bitrix\Disk;
use Bitrix\Disk\Internals\Engine\ActionFilter\CheckReadPermission;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Internals\ObjectOptionsTable;
use Bitrix\Disk\Internals\SharingTable;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Disk\Type\AccessRight;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Disk\User;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessCheckHandler;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkParentFoldersAccessCheckHandler;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

final class AccessRights extends Engine\Controller
{
	protected RequiredParameter $requiredParameterFilter;
	protected UnifiedLinkAccessChecker $readAccessChecker;
	protected ExternalLinkProvider $externalLinkProvider;

	public function __construct(?Request $request = null)
	{
		$this->requiredParameterFilter = new RequiredParameter('object', false);

		$this->readAccessChecker = new UnifiedLinkAccessChecker(
			targetAccessLevel: UnifiedLinkAccessLevel::Read,
			argumentName: 'object',
		);

		$this->externalLinkProvider = ServiceLocator::getInstance()->get(ExternalLinkProvider::class);

		parent::__construct($request);
	}

	/**
	 * {@inheritDoc}
	 */
	public function configureActions(): array
	{
		return [
			'getUnified' => [
				'-prefilters' => [
					CheckReadPermission::class,
				],
				'+prefilters' => [
					$this->requiredParameterFilter,
					$this->readAccessChecker,
				],
				'+postfilters' => [
					$this->requiredParameterFilter,
				],
			],
			'setUnifiedPublicLinkRights' => [
				'-prefilters' => [
					CheckReadPermission::class,
				],
				'+prefilters' => [
					$this->requiredParameterFilter,
					$this->readAccessChecker,
				],
				'+postfilters' => [
					$this->requiredParameterFilter,
				],
			],
		];
	}

	/**
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				BaseObject::class,
				'object',
				function ($className, string $uniqueCode): ?BaseObject {
					return BaseObject::loadByUniqueCode($uniqueCode);
				},
			),
		];
	}

	public function getAction($objectId): ?array
	{
		/** @var File|Folder $object */
		$object = BaseObject::loadById((int)$objectId, array('STORAGE'));
		if (!$object || !$object->getRealObject())
		{
			$this->errorCollection[] = new \Bitrix\Disk\Internals\Error\Error('DISK_FOLDER_LIST_ERROR_COULD_NOT_FIND_OBJECT', \DiskFolderListAjaxController::ERROR_COULD_NOT_FIND_OBJECT);
			return null;
		}

		$securityContext = $object->getStorage()?->getCurrentUserSecurityContext();
		if (!$securityContext || !$object->canRead($securityContext))
		{
			return null;
		}

		$canUpdate = $object->canUpdate($securityContext);

		return $this->getInternal($object, $canUpdate, $securityContext);
	}

	public function getUnifiedAction(?BaseObject $object): ?array
	{
		$accessLevel = $this->readAccessChecker->getAccessLevel();
		$canEdit = $accessLevel->value >= UnifiedLinkAccessLevel::Edit->value;
		$securityContext = $object?->getStorage()?->getCurrentUserSecurityContext(true);

		if (!$securityContext)
		{
			return null;
		}

		return $this->getInternal($object, $canEdit, $securityContext);
	}

	public function setAction($objectId): void
	{
		/** @var File|Folder $object */
		$object = BaseObject::loadById((int)$objectId, array('STORAGE'));
		if(!$object)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_FOLDER_LIST_ERROR_COULD_NOT_FIND_OBJECT'), self::ERROR_COULD_NOT_FIND_OBJECT);
			$this->sendJsonErrorResponse();
		}

		$checkManageMembersAccessError = $this->checkManageMembersAccess($object);

		if ($checkManageMembersAccessError instanceof MembersAccessDisableReason)
		{
			$this->addError(new Error(
				message: '',
				customData: [
					'reason' => $checkManageMembersAccessError,
				],
			));

			return;
		}

		$securityContext = $object->getStorage()->getCurrentUserSecurityContext();
		if(!$object->canChangeRights($securityContext))
		{
			$this->sendJsonAccessDeniedResponse();
		}

		$this->handlePrivateFlags($object);
		$this->processActionChangeSharingAndRights($object, $securityContext);
	}

	private function getPublicLinkData(
		BaseObject $baseObject,
		bool $isObjectEditable,
		Disk\Security\SecurityContext $securityContext,
	)
	{
		$realObject = $baseObject->getRealObject();
		$extLink = $this->externalLinkProvider->getForUse($realObject->getId());
		$ttl = null;
		$rightsList = null;

		if ($extLink)
		{
			$ttl = $extLink->getDeathTime()?->getTimestamp();

			$rightsList = [
				AccessRight::Read->value,
			];

			if ($isObjectEditable)
			{
				$rightsList[] = AccessRight::Edit->value;
			}
		}

		return [
			'enabled' => (bool)$extLink,
			'link' => $extLink ? $extLink->generateUrl() : null,
			'rights' => $extLink ? AccessRight::tryFromExternalLinkRights($extLink->getAccessRight())?->value : null,
			'canDownloadWithReadAccess' => $extLink ? $extLink->isCanDownloadWithReadAccess() : null,
			'canEditSettings' => $extLink ? $extLink->canEditSettings() : true,
			'publicLinkTtl' => $ttl,
			'password' => $extLink ? $extLink->hasPassword() : null,
			'disableReason' => $this->checkManageExternalLink($realObject, $securityContext),
			'rightsList' => $rightsList,
		];
	}

	public function setPublicLinkRightsAction(BaseObject $object): ?array
	{
		$securityContext = $object->getStorage()?->getCurrentUserSecurityContext();
		if (!$securityContext || !$object->canRead($securityContext))
		{
			return null;
		}

		$canUpdate = $object->canUpdate($securityContext);

		return $this->setPublicLinkRights($object, $canUpdate, $securityContext);
	}

	public function setUnifiedPublicLinkRightsAction(?BaseObject $object): ?array
	{
		$accessLevel = $this->readAccessChecker->getAccessLevel();
		$canEdit = $accessLevel->value >= UnifiedLinkAccessLevel::Edit->value;
		$securityContext = $object?->getStorage()?->getCurrentUserSecurityContext(true);

		if (!$securityContext)
		{
			return null;
		}

		return $this->setPublicLinkRights($object, $canEdit, $securityContext);
	}

	protected function getInternal(
		BaseObject $object,
		bool $canEdit,
		Disk\Security\SecurityContext $securityContext,
	): ?array
	{
		$isFolder = $object instanceof Folder;
		$objectOptions = $object->getRealObject()->getObjectOptions();
		$isObjectEditable = $canEdit && $this->isObjectEditable($object->getRealObject());
		$membersAccessDisableReason = $this->checkManageMembersAccess($object->getRealObject());

		return [
			'fileName' => $object->getName(),
			'typeFile' => $isFolder ? null : $object->getTypeFile(),
			'isFolder' => $isFolder,
			'allowManagePublicAccessWithViewingRights' =>
				$objectOptions[ObjectOptionsTable::NAME_ALLOW_MANAGE_PUBLIC_ACCESS_ON_READ]
			,
			'allowDownloadingWithViewingRights'	=> $objectOptions[ObjectOptionsTable::NAME_ALLOW_DOWNLOAD_ON_READ],
			'membersAccessDisableReason' => $membersAccessDisableReason,
			'membersAccess' => $this->processActionShowSharingDetailChangeRights($object, $membersAccessDisableReason),
			'publicLink' => $this->getPublicLinkData(
				$object,
				$isObjectEditable,
				$securityContext,
			),
		];
	}

	protected function setPublicLinkRights(
		BaseObject $baseObject,
		bool $canEdit,
		Disk\Security\SecurityContext $securityContext,
	): ?array
	{
		$realObject = $baseObject->getRealObject();
		$checkManageExternalLinkError = $this->checkManageExternalLink($realObject, $securityContext);

		if ($checkManageExternalLinkError instanceof ExternalLinkDisableReason)
		{
			$this->addError(new Error(
				message: '',
				customData: [
					'reason' => $checkManageExternalLinkError,
				],
			));

			return null;
		}

		$data = $this->getRequest()->getJsonList()->toArray();
		$extLink = $this->externalLinkProvider->getForUse($realObject->getId());
		$enabled = boolval($data['enabled'] ?? false);
		$isObjectEditable = $canEdit && $this->isObjectEditable($realObject);
		$settingsFields = ['rights', 'canDownloadWithReadAccess', 'newPassword', 'publicLinkTtl'];

		if (!$enabled)
		{
			if ($extLink)
			{
				$externalLinkId = (int)$extLink->getId();
				if ($extLink->delete())
				{
					Application::getInstance()->addBackgroundJob(
						static function () use ($realObject, $externalLinkId) {
							$service = new OnlyOfficeSessionTerminationService($realObject);
							$service->terminateSessionsByExternalLink($externalLinkId);
						},
					);
				}
			}

			return $this->getPublicLinkData(
				$realObject,
				$isObjectEditable,
				$securityContext,
			);
		}

		if ($extLink && array_key_exists('canEditSettings', $data))
		{
			$this->addError(new Error(
				'External link settings edit permission cannot be changed',
				Disk\ExternalLink::ERROR_SETTINGS_EDIT_DENIED,
			));

			return null;
		}

		if ($extLink && !$extLink->canEditSettings() && $this->hasAnyKey($data, $settingsFields))
		{
			$this->addError(new Error(
				'External link settings editing is denied',
				Disk\ExternalLink::ERROR_SETTINGS_EDIT_DENIED,
			));

			return null;
		}

		if (!$extLink)
		{
			$addData = [
				'CREATED_BY' => $this->getUser()->getId(),
				'TYPE' => ExternalLinkTable::TYPE_MANUAL,
			];

			if (array_key_exists('canEditSettings', $data))
			{
				$addData['CAN_EDIT_SETTINGS'] = (bool)$data['canEditSettings'];
			}

			if ($isObjectEditable && isset($data['rights']))
			{
				$rights = AccessRight::tryFrom($data['rights']);
				if ($rights)
				{
					$addData['ACCESS_RIGHT'] = $rights->toExternalLinkRight();
				}
			}

			if (isset($data['canDownloadWithReadAccess']))
			{
				$addData['CAN_DOWNLOAD_WITH_READ_ACCESS'] = $data['canDownloadWithReadAccess'] ? 1 : 0;
			}

			if (isset($data['newPassword']) && $data['newPassword'] !== false)
			{
				$addData['PASSWORD'] = (string)$data['newPassword'];
			}

			$publicLinkTtl = $data['publicLinkTtl'] ?? null;
			if (is_int($publicLinkTtl) && $publicLinkTtl > time())
			{
				$addData['DEATH_TIME'] = DateTime::createFromTimestamp($publicLinkTtl);
			}

			$extLink = $realObject->addExternalLink($addData);
			if (!$extLink)
			{
				$this->addErrors($realObject->getErrors());

				return null;
			}

			return $this->getPublicLinkData($realObject, $isObjectEditable, $securityContext);
		}

		if ($isObjectEditable && isset($data['rights']))
		{
			$rights = AccessRight::tryFrom($data['rights']);
			if ($rights)
			{
				$extLink->changeAccessRight($rights->toExternalLinkRight());
			}
		}

		if (isset($data['canDownloadWithReadAccess']) && (bool)$data['canDownloadWithReadAccess'] !== $extLink->isCanDownloadWithReadAccess())
		{
			$extLink->changeCanDownloadWithReadAccess($data['canDownloadWithReadAccess']);
		}

		if (isset($data['newPassword']))
		{
			if ($data['newPassword'] === false)
			{
				$extLink->revokePassword();
			}
			else
			{
				$extLink->changePassword((string)$data['newPassword']);
			}
		}

		$publicLinkTtl = $data['publicLinkTtl'] ?? null;

		if (is_int($publicLinkTtl) || $publicLinkTtl === false)
		{
			$deathTime = $publicLinkTtl === false ? false : DateTime::createFromTimestamp($publicLinkTtl);

			if (!$deathTime)
			{
				$extLink->revokeDeathTime();
			}
			elseif($publicLinkTtl > time())
			{
				$extLink->changeDeathTime($deathTime);
			}
		}

		return $this->getPublicLinkData($realObject, $isObjectEditable, $securityContext);
	}

	private function hasAnyKey(array $data, array $keys): bool
	{
		foreach ($keys as $key)
		{
			if (array_key_exists($key, $data))
			{
				return true;
			}
		}

		return false;
	}

	private function getUser()
	{
		global $USER;

		return $USER;
	}

	/** @see DiskFolderListAjaxController::processActionShowSharingDetailChangeRights() */
	private function processActionShowSharingDetailChangeRights(
		BaseObject $object,
		?MembersAccessDisableReason $disableReason,
	)
	{
		$rightsManager = Driver::getInstance()->getRightsManager();
		$securityContext = $object->getStorage()?->getCurrentUserSecurityContext();
		if (!$securityContext || !$object->canRead($securityContext))
		{
			return $this->getUnifiedLinkOnlyMembersAccess($object);
		}
		//user has only read right. And he can't see on another sharing
		if (!$object->canShare($securityContext) && !$object->canChangeRights($securityContext))
		{
			/** @var User $user */
			$user = User::getById($this->getUser()->getId());
			$entityList = array(
				array(
					'entityId' => \Bitrix\Disk\Sharing::CODE_USER . $this->getUser()->getId(),
					'name' => $user->getFormattedName(),
					'right' => $rightsManager->getPseudoMaxTaskByObjectForUser($object, $user->getId()),
					'avatar' => $user->getAvatarSrc(),
					'ownAvatar' => $user->getOwnAvatarSrc(),
					'type' => 'users',
					'memberType' => SharingMemberType::forUser($user)?->value,
				)
			);
		}
		else
		{
			$entityList = self::sortMembersByGrantOrder($object->getMembersOfSharing());
		}

		$selected = array();
		foreach ($entityList as $entity)
		{
			$selected[] = $entity['entityId'];
		}
		$destination = \Bitrix\Disk\Ui\Destination::getSocNetDestination($this->getUser()->getId(), $selected);

		$rightsManager = Driver::getInstance()->getRightsManager();
		$canOnlyShare = $object->canShare($securityContext) && !$object->canChangeRights($securityContext);
		$maxTaskName = $rightsManager::TASK_FULL;
		if ($canOnlyShare)
		{
			$maxTaskName = $rightsManager->getPseudoMaxTaskByObjectForUser($object, $this->getUser()->getId());
		}
		$owner = $this->getOwner($object);
		$owner['canOnlyShare'] = $canOnlyShare;
		$owner['maxTaskName'] = $maxTaskName;

		$response = array(
			'members' => $entityList,
			'owner' => $owner,
			'destination' => array(
				'items' => array(
					'users' => $destination['USERS'],
					'groups' => array(),
					'sonetgroups' => $destination['SONETGROUPS'],
					'department' => $destination['DEPARTMENT'],
					'departmentRelation' => $destination['DEPARTMENT_RELATION'],
				),
				'itemsLast' => array(
					'users' => $destination['LAST']['USERS'],
					'groups' => array(),
					'sonetgroups' => $destination['LAST']['SONETGROUPS'],
					'department' => $destination['LAST']['DEPARTMENT'],
				),
				'itemsSelected' => $destination['SELECTED'],
			),
			'canChangeRights' =>
				is_null($disableReason)
				&& $object->canChangeRights($securityContext)
			,
		);

		$realObject = $object->getRealObject();

		if ($realObject->supportsUnifiedLink())
		{
			$response['unifiedLink'] = $this->getUnifiedLinkData($realObject);
		}

		return $response;
	}

	/**
	 * Orders members the way the access was granted, which is the order the user picked them in.
	 * {@see BaseObject::getMembersOfSharing()} walks entity types one after another, so a user added
	 * between two departments lands after both of them once the popup reloads the rights.
	 * Only this popup is reordered: the same list is served to the file grid, the folder list and the
	 * file view panel, and those keep the grouping by type.
	 * A member with no sharing id keeps its place relative to the others: sorting is stable since PHP 8.
	 * @param array $members Members of sharing.
	 * @return array
	 */
	private static function sortMembersByGrantOrder(array $members): array
	{
		usort(
			$members,
			static fn(array $a, array $b): int => (int)($a['sharingId'] ?? 0) <=> (int)($b['sharingId'] ?? 0),
		);

		return $members;
	}

	protected function getOwner(BaseObject $object): array
	{
		$proxyType = $object->getRealObject()->getStorage()->getProxyType();
		if ($proxyType instanceof \Bitrix\Im\Disk\ProxyType\Im)
		{
			$createUser = $object->getRealObject()->getCreateUser();

			return [
				'name' => $createUser->getFormattedName(),
				'avatar' => $createUser->getAvatarSrc(58, 58),
				'link' => $createUser->getDetailUrl(),
			];
		}

		return [
			'name' => $proxyType->getEntityTitle(),
			'avatar' => $proxyType->getEntityImageSrc(58, 58),
			'link' => $proxyType->getEntityUrl(),
		];
	}

	/**
	 * Visitor without direct rights sees no members, but still has to get the link he came by.
	 */
	private function getUnifiedLinkOnlyMembersAccess(BaseObject $object): array|false
	{
		$realObject = $object->getRealObject();
		if (!$realObject->supportsUnifiedLink())
		{
			return false;
		}

		$parentFoldersAccessLevel = $this->getUnifiedLinkParentFoldersAccessLevel($realObject);
		$accessLevel = $this->getUnifiedLinkCurrentAccessLevel($realObject, $parentFoldersAccessLevel);
		if ($accessLevel === UnifiedLinkAccessLevel::Denied)
		{
			return false;
		}

		return [
			'members' => [],
			'canChangeRights' => false,
			'unifiedLink' => $this->getUnifiedLinkData($realObject, $accessLevel),
		];
	}

	private function getUnifiedLinkData(
		BaseObject $baseObject,
		?UnifiedLinkAccessLevel $accessLevel = null,
	): array
	{
		$link = Driver::getInstance()->getUrlManager()->getUnifiedLink($baseObject, [
			'absolute' => true,
		]);

		$parentFoldersAccessLevel = $this->getUnifiedLinkParentFoldersAccessLevel($baseObject);

		return [
			'link' => $link,
			'currentAccessLevel' => $accessLevel
				?? $this->getUnifiedLinkCurrentAccessLevel($baseObject, $parentFoldersAccessLevel)
			,
			'availableAccessLevels' => array_map(static fn (UnifiedLinkAccessLevel $accessLevel) => [
				'value' => $accessLevel->value,
				'name' => $accessLevel->getTitle(),
			], $this->getUnifiedLinkAvailableAccessLevels($parentFoldersAccessLevel)),
		];
	}

	protected function getUnifiedLinkParentFoldersAccessLevel(BaseObject $baseObject): UnifiedLinkAccessLevel
	{
		$userId = (int)CurrentUser::get()->getId();
		// An anonymous request resolves to the system user, who is an intranet one by definition
		// and would be granted the level of the link.
		if ($userId <= 0)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		return (new UnifiedLinkParentFoldersAccessCheckHandler($userId))->check($baseObject);
	}

	protected function getUnifiedLinkAvailableAccessLevels(UnifiedLinkAccessLevel $parentFoldersAccessLevel): array
	{
		return array_values(array_filter(
			UnifiedLinkAccessLevel::cases(),
			static fn (UnifiedLinkAccessLevel $accessLevel): bool => $accessLevel->value >= $parentFoldersAccessLevel->value,
		));
	}

	protected function getUnifiedLinkCurrentAccessLevel(
		BaseObject $baseObject,
		UnifiedLinkAccessLevel $parentFoldersAccessLevel,
	): UnifiedLinkAccessLevel
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		$currentAccessLevel = (new UnifiedLinkAccessCheckHandler($userId))->check($baseObject);

		return $parentFoldersAccessLevel->value > $currentAccessLevel->value
			? $parentFoldersAccessLevel
			: $currentAccessLevel
		;
	}

	/**
	 * @throws ArgumentException
	 */
	protected function handlePrivateFlags(BaseObject $baseObject): void
	{
		$realObject = $baseObject->getRealObject();

		$allowManagePublicAccessWithViewingRights = $this->toBoolOrNull(
			$this->request->getPost('allowManagePublicAccessWithViewingRights')
		);

		if (is_bool($allowManagePublicAccessWithViewingRights))
		{
			$realObject->setObjectOption(
				name: ObjectOptionsTable::NAME_ALLOW_MANAGE_PUBLIC_ACCESS_ON_READ,
				value: $allowManagePublicAccessWithViewingRights,
			);
		}

		$allowDownloadingWithViewingRights = $this->toBoolOrNull(
			$this->request->getPost('allowDownloadingWithViewingRights')
		);

		if (is_bool($allowDownloadingWithViewingRights))
		{
			$realObject->setObjectOption(
				name: ObjectOptionsTable::NAME_ALLOW_DOWNLOAD_ON_READ,
				value: $allowDownloadingWithViewingRights,
			);
		}
	}

	protected function processActionChangeSharingAndRights(
		BaseObject $object,
		Disk\Security\SecurityContext $securityContext,
	)
	{
		Driver::getInstance()->getTrackedObjectManager()->refresh($object, $this->getUser()->getId());

		$isBoard = $object instanceof File && $object->getTypeFile() == TypeFile::FLIPCHART;
		$isFolder = $object instanceof Folder;

		$currentUserId = $this->getUser()->getId();
		$entityToNewShared = $this->request->getPost('entityToNewShared');
		if(!empty($entityToNewShared) && is_array($entityToNewShared))
		{
			$unifiedLinkAccessChanged = false;
			$unifiedLinkData = $entityToNewShared['unifiedLink'];
			if (isset($unifiedLinkData['newAccessLevel']))
			{
				$unifiedLinkNewAccessLevel = UnifiedLinkAccessLevel::tryFrom($unifiedLinkData['newAccessLevel']);
				if ($unifiedLinkNewAccessLevel !== null)
				{
					if (!$this->isUnifiedLinkAccessLevelAllowed($object->getRealObject(), $unifiedLinkNewAccessLevel))
					{
						$this->addError(new Error(
							message: 'Access level is lower than allowed.',
							code: 'DISK_UNIFIED_LINK_ACCESS_LEVEL_RESTRICTED',
						));

						$this->sendJsonErrorResponse([
							'unifiedLinkChangeError' => true,
						]);
					}

					$result = (new SetAccessCommand($object->getRealObjectId(), $unifiedLinkNewAccessLevel))->run();

					if ($result->isSuccess())
					{
						$unifiedLinkAccessChanged = true;
					}
					else
					{
						$this->sendJsonErrorResponse([
							'unifiedLinkChangeError' => true,
						]);
					}
				}
			}

			$extendedRights = $entityToNewShared;
			$newExtendedRightsReformat = array();
			foreach($extendedRights as $entityId => $right)
			{
				switch($right['right'])
				{
					case \Bitrix\Disk\RightsManager::TASK_READ:
					case \Bitrix\Disk\RightsManager::TASK_ADD:
					case \Bitrix\Disk\RightsManager::TASK_EDIT:
					case \Bitrix\Disk\RightsManager::TASK_FULL:
						$newExtendedRightsReformat[$entityId] = $right['right'];
						break;
				}
			}
			//todo move this code to Object or Sharing model (reset sharing)
			$query = Disk\Sharing::getList(array(
				'filter' => array(
					'REAL_OBJECT_ID' => $object->getRealObjectId(),
					'REAL_STORAGE_ID' => $object->getRealObject()->getStorageId(),
					'!=STATUS' => SharingTable::STATUS_IS_DECLINED,
					'PARENT_ID' => null,
				),
			));
			$needToOverwrite = $needToDelete = $needToAdd = array();
			while($sharingRow = $query->fetch())
			{
				if(isset($newExtendedRightsReformat[$sharingRow['TO_ENTITY']]))
				{
					if($newExtendedRightsReformat[$sharingRow['TO_ENTITY']] != $sharingRow['TASK_NAME'])
					{
						$needToOverwrite[$sharingRow['TO_ENTITY']] = $sharingRow;
					}
					elseif($newExtendedRightsReformat[$sharingRow['TO_ENTITY']] == $sharingRow['TASK_NAME'])
					{
						unset($newExtendedRightsReformat[$sharingRow['TO_ENTITY']]);
					}
				}
				else
				{
					$needToDelete[$sharingRow['TO_ENTITY']] = $sharingRow;
				}
			}

			$needToAdd = array_diff_key($newExtendedRightsReformat, $needToOverwrite);
			foreach ($needToAdd as $entity => $taskName)
			{
				if (!Disk\Sharing::hasRightToKnowAboutEntity($currentUserId, $entity))
				{
					unset($needToAdd[$entity]);
				}
			}

			if($needToAdd)
			{

				$sharings = Disk\Sharing::addToManyEntities(array(
					'FROM_ENTITY' => Disk\Sharing::CODE_USER . $currentUserId,
					'REAL_OBJECT' => $object,
					'CREATED_BY' => $this->getUser()->getId(),
					'CAN_FORWARD' => false,
				), $needToAdd, $this->errorCollection);

				if (is_array($sharings))
				{
					$destinationCodes = [];
					foreach($sharings as $key => $sharing)
					{
						$destinationCodes[] = $sharing->getToEntity();
					}

					if (!empty($destinationCodes))
					{
						\Bitrix\Main\FinderDestTable::merge([
							"CONTEXT" => "DISK_SHARE",
							"CODE" => \Bitrix\Main\FinderDestTable::convertRights(array_unique($destinationCodes))
						]);
					}

					if ($isBoard)
					{
						Application::getInstance()->addBackgroundJob(function() {
							$event = new AnalyticsEvent('give_access', 'boards', 'boards');
							$event->send();
						});
					}
				}
			}
			if($needToOverwrite)
			{
				$rightsManager = Driver::getInstance()->getRightsManager();
				foreach($needToOverwrite as $sharingRow)
				{
					$rightsManager->deleteByDomain($object->getRealObject(), $rightsManager->getSharingDomain($sharingRow['ID']));
				}

				$newRights = array();
				foreach($needToOverwrite as $sharingRow)
				{
					$sharingDomain = $rightsManager->getSharingDomain($sharingRow['ID']);
					$isSharingToGroup = $sharingRow['TYPE'] == SharingTable::TYPE_TO_GROUP;
					$accessCodeSuffix = $isSharingToGroup ? '_K' : ''; // todo refactor. Move this logic to Sharing
					$newRights[] = array(
						'ACCESS_CODE' => $sharingRow['TO_ENTITY'] . $accessCodeSuffix,
						'TASK_ID' => $rightsManager->getTaskIdByName($newExtendedRightsReformat[$sharingRow['TO_ENTITY']]),
						'DOMAIN' => $sharingDomain,
					);
					//todo refactor. Move most logic to Sharing and SharingTable!
					if($sharingRow['TYPE'] == SharingTable::TYPE_TO_DEPARTMENT)
					{
						/** @var \Bitrix\Disk\Sharing $sharingModel */
						$sharingModel = Disk\Sharing::buildFromArray($sharingRow);
						$sharingModel->changeTaskName($newExtendedRightsReformat[$sharingRow['TO_ENTITY']]);
					}
					else
					{
						SharingTable::update($sharingRow['ID'], array(
							'TASK_NAME' => $newExtendedRightsReformat[$sharingRow['TO_ENTITY']],
						));
					}
				}

				if($newRights)
				{
					$rightsManager->append($object->getRealObject(), $newRights);
				}
			}
			if($needToDelete)
			{
				$ids = array();
				foreach($needToDelete as $sharingRow)
				{
					$ids[] = $sharingRow['ID'];
				}

				foreach(Disk\Sharing::getModelList(array('filter' => array('ID' => $ids))) as $sharing)
				{
					$sharing->delete($currentUserId);
				}
			}

			Application::getInstance()->addBackgroundJob(
				function () use ($object) {
					$this->terminateSessionsWithInsufficientRights($object);
				},
			);

			if ($isFolder)
			{
				/** @var Folder $object */
				$this->terminateSessionsForAllBoardsInFolder($object, $securityContext);
			}

			$this->sendJsonSuccessResponse([
				'unifiedLinkAccessChanged' => $unifiedLinkAccessChanged,
			]);
		}
		else
		{
			//user delete all sharing
			foreach ($object->getRealObject()->getSharingsAsReal() as $sharing)
			{
				$sharing->delete($currentUserId);
			}

			Application::getInstance()->addBackgroundJob(
				function () use ($object) {
					$this->terminateSessionsWithInsufficientRights($object);
				},
			);

			if ($isFolder)
			{
				/** @var Folder $object */
				$this->terminateSessionsForAllBoardsInFolder($object, $securityContext);
			}

			$this->sendJsonSuccessResponse();
		}
	}

	protected function isUnifiedLinkAccessLevelAllowed(
		BaseObject $baseObject,
		UnifiedLinkAccessLevel $accessLevel,
	): bool
	{
		return $accessLevel->value >= $this->getUnifiedLinkParentFoldersAccessLevel($baseObject)->value;
	}

	/**
	 * @param BaseObject $object
	 * @return void
	 */
	private function terminateSessionsWithInsufficientRights(BaseObject $object): void
	{
		$sessionTerminationServices = (new Disk\Document\SessionTerminationServiceFactory($object))->createAll();
		foreach ($sessionTerminationServices as $sessionTerminationService)
		{
			$sessionTerminationService->terminateSessionsWithInsufficientRights();
		}
	}

	protected function terminateSessionsForAllBoardsInFolder(Folder $object, Disk\Security\SecurityContext $securityContext): void
	{
		$objectsInFolder = $object->getDescendants($securityContext, [
			'filter' => [
				'=TYPE' => Disk\Internals\ObjectTable::TYPE_FILE,
				'=TYPE_FILE' => TypeFile::FLIPCHART,
			],
		]);

		$boardsInFolder = [];
		foreach ($objectsInFolder as $descObject)
		{
			$boardsInFolder[] = $descObject->getRealObject();
		}

		if ($boardsInFolder)
		{
			Application::getInstance()->addBackgroundJob(
				function () use ($boardsInFolder) {
					foreach ($boardsInFolder as $board)
					{
						$this->terminateSessionsWithInsufficientRights($board);
					}
				},
			);
		}
	}

	/**
	 * Sends JSON response and terminates controller.
	 * @param mixed $response
	 * @param null|array $params
	 * @return void
	 */
	protected function sendJsonResponse($response, $params = null)
	{
		if(!defined('PUBLIC_AJAX_MODE'))
		{
			define('PUBLIC_AJAX_MODE', true);
		}

		$this->restartBuffer();

		if(!empty($params['http_status']) && $params['http_status'] == 403)
		{
			header('HTTP/1.0 403 Forbidden', true, 403);
		}
		if(!empty($params['http_status']) && $params['http_status'] == 500)
		{
			header('HTTP/1.0 500 Internal Server Error', true, 500);
		}
		if(!empty($params['http_status']) && $params['http_status'] == 510)
		{
			header('HTTP/1.0 510 Not Extended', true, 510);
		}

		header('Content-Type:application/json; charset=UTF-8');
		echo Json::encode($response);

		$this->end();
	}

	/**
	 * Sends JSON response with status "error" and with errors and terminates controller.
	 * @param array $response Data to response.
	 * @return void
	 */
	protected function sendJsonErrorResponse(array $response = array())
	{
		$errors = array();
		foreach($this->getErrors() as $error)
		{
			/** @var Error $error */
			$errors[] = array(
				'message' => $error->getMessage(),
				'code' => $error->getCode(),
			);
		}
		unset($error);
		$response['status'] = Disk\Internals\Controller::STATUS_ERROR;
		$response['errors'] = $errors;
		$this->sendJsonResponse($response);
	}

	final protected function restartBuffer()
	{
		global $APPLICATION;
		$APPLICATION->restartBuffer();
	}

	/**
	 * Terminates controller and application.
	 * This method replaces "die()" or "exit()" and ensures life cycle of application.
	 * @return void
	 */
	protected function end()
	{
		$this->logDebugInfo();

		/** @noinspection PhpUndefinedClassInspection */
		\CMain::finalActions();
		die;
	}

	/**
	 * Sends JSON response with status "denied" and terminates controller.
	 * @param string $message Message.
	 * @return void
	 */
	protected function sendJsonAccessDeniedResponse($message = '')
	{
		$this->sendJsonResponse(array(
			'status' => Disk\Internals\Controller::STATUS_DENIED,
			'message' => $message,
		));
	}

	/**
	 * Sends JSON response with status "success" and mixed data, and terminates controller.
	 * @param array $response Data to response.
	 * @return void
	 */
	protected function sendJsonSuccessResponse(array $response = array())
	{
		$response['status'] = Disk\Internals\Controller::STATUS_SUCCESS;
		$this->sendJsonResponse($response);
	}

	protected function isObjectEditable(BaseObject $baseObject): bool
	{
		return
			$baseObject instanceof File
			&& DocumentHandler::isEditable($baseObject->getExtension())
		;
	}

	protected function checkManageMembersAccess(BaseObject $baseObject): ?MembersAccessDisableReason
	{
		if ($baseObject instanceof Folder)
		{
			if (!Bitrix24Manager::isFeatureEnabled('disk_folder_sharing'))
			{
				return MembersAccessDisableReason::Feature;
			}

			return null;
		}

		if ($baseObject instanceof File)
		{
			$isBoardType = (int)$baseObject->getTypeFile() === TypeFile::BOARD;

			if ($isBoardType)
			{
				return null;
			}

			if (!Bitrix24Manager::isFeatureEnabled('disk_file_sharing'))
			{
				return MembersAccessDisableReason::Feature;
			}

			return null;
		}

		return null;
	}

	protected function checkManageExternalLink(
		BaseObject $baseObject,
		Disk\Security\SecurityContext $securityContext,
	): ?ExternalLinkDisableReason
	{
		if ($baseObject instanceof Folder)
		{
			$featureName = 'disk_manual_external_folder';
		}
		elseif ($baseObject instanceof File)
		{
			$isBoardType = (int)$baseObject->getTypeFile() === TypeFile::BOARD;
			$featureName = $isBoardType ? 'disk_board_external_link' : 'disk_manual_external_link';
		}
		else
		{
			$featureName = null;
		}

		if (is_string($featureName) && !Bitrix24Manager::isFeatureEnabled($featureName))
		{
			return ExternalLinkDisableReason::Feature;
		}

		if (!Configuration::isPossibleToShowExternalLinkControl())
		{
			return ExternalLinkDisableReason::Option;
		}

		if (!$baseObject->canManageExternalLink($securityContext))
		{
			return ExternalLinkDisableReason::ObjectOption;
		}

		return null;
	}

	/**
	 * @param mixed $value
	 * @return bool|null
	 */
	protected function toBoolOrNull(mixed $value): ?bool
	{
		return match ($value)
		{
			'true' => true,
			'false' => false,
			default => null,
		};
	}
}
