<?php

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Controller\ActionFilter\RequiredParameter;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\UnifiedLinkAccessChecker;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internal\Command\UnifiedLink\SetAccessCommand;
use Bitrix\Disk\Internal\Enum\ExternalLinkDisableReason;
use Bitrix\Disk\Internal\Enum\MembersAccessDisableReason;
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
		$this->requiredParameterFilter = new RequiredParameter('file', false);
		$this->readAccessChecker = new UnifiedLinkAccessChecker(UnifiedLinkAccessLevel::Read);
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
				File::class,
				'file',
				function ($className, string $uniqueCode): ?File {
					return File::loadByUniqueCode($uniqueCode);
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
		$onlyRead = !$canUpdate;

		return $this->getInternal($object, $onlyRead, $canUpdate);
	}

	public function getUnifiedAction(?File $file): ?array
	{
		$accessLevel = $this->readAccessChecker->getAccessLevel();
		$onlyRead = $accessLevel === UnifiedLinkAccessLevel::Read;
		$canEdit = $accessLevel->value >= UnifiedLinkAccessLevel::Edit->value;

		return $this->getInternal($file, $onlyRead, $canEdit);
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

	private function getPublicLinkData(File $file, bool $onlyRead, bool $isObjectEditable)
	{
		$realFile = $file->getRealObject();
		$extLink = $this->externalLinkProvider->getForUse($realFile->getId());
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
			'publicLinkTtl' => $ttl,
			'password' => $extLink ? $extLink->hasPassword() : null,
			'disableReason' => $this->checkManageExternalLink($realFile, $onlyRead),
			'rightsList' => $rightsList,
		];
	}

	public function setPublicLinkRightsAction(File $file): ?array
	{
		$securityContext = $file->getStorage()?->getCurrentUserSecurityContext();
		if (!$securityContext || !$file->canRead($securityContext))
		{
			return null;
		}

		$canUpdate = $file->canUpdate($securityContext);
		$onlyRead = !$canUpdate;

		return $this->setPublicLinkRights($file, $onlyRead, $canUpdate);
	}

	public function setUnifiedPublicLinkRightsAction(?File $file): ?array
	{
		$accessLevel = $this->readAccessChecker->getAccessLevel();
		$onlyRead = $accessLevel === UnifiedLinkAccessLevel::Read;
		$canEdit = $accessLevel->value >= UnifiedLinkAccessLevel::Edit->value;

		return $this->setPublicLinkRights($file, $onlyRead, $canEdit);
	}

	protected function getInternal(File|Folder $object, bool $onlyRead, bool $canEdit): ?array
	{
		$isFolder = $object instanceof Folder;
		$objectOptions = $object->getRealObject()->getObjectOptions();
		$isObjectEditable = $canEdit && $this->isObjectEditable($object->getRealObject());
		$membersAccessDisableReason = $this->checkManageMembersAccess($object->getRealObject());

		return [
			'fileName' => $object->getName(),
			'typeFile' => $isFolder ? null : $object->getTypeFile(),
			'isFolder' => $isFolder,
			'allowManagePublicAccessWithViewingRights' => $objectOptions[ObjectOptionsTable::NAME_ALLOW_MANAGE_PUBLIC_ACCESS_ON_READ],
			'allowDownloadingWithViewingRights'	=> $objectOptions[ObjectOptionsTable::NAME_ALLOW_DOWNLOAD_ON_READ],
			'membersAccessDisableReason' => $membersAccessDisableReason,
			'membersAccess' => $this->processActionShowSharingDetailChangeRights($object, $membersAccessDisableReason),
			'publicLink' => $isFolder ? null : $this->getPublicLinkData($object, $onlyRead, $isObjectEditable),
		];
	}

	protected function setPublicLinkRights(File $file, bool $onlyRead, bool $canEdit): ?array
	{
		$realFile = $file->getRealObject();
		$checkManageExternalLinkError = $this->checkManageExternalLink($realFile, $onlyRead);

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
		$extLink = $this->externalLinkProvider->getForUse($realFile->getId());
		$enabled = boolval($data['enabled'] ?? false);
		$isObjectEditable = $canEdit && $this->isObjectEditable($realFile);

		if (!$enabled)
		{
			if ($extLink)
			{
				$extLink->delete();
			}

			return $this->getPublicLinkData($realFile, $onlyRead, $isObjectEditable); // todo: work on return values
		}

		if (!$extLink)
		{
			$extLink = $realFile->addExternalLink([
				'CREATED_BY' => $this->getUser()->getId(),
				'TYPE' => ExternalLinkTable::TYPE_MANUAL,
			]);
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

		$publicLinkTtl = $data['publicLinkTtl'];

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

		return $this->getPublicLinkData($realFile, $onlyRead, $isObjectEditable);
	}

	private function getUser()
	{
		global $USER;

		return $USER;
	}

	/** @see DiskFolderListAjaxController::processActionShowSharingDetailChangeRights() */
	private function processActionShowSharingDetailChangeRights(
		File|Folder $object,
		?MembersAccessDisableReason $disableReason,
	)
	{
		$rightsManager = Driver::getInstance()->getRightsManager();
		$securityContext = $object->getStorage()?->getCurrentUserSecurityContext();
		if (!$securityContext || !$object->canRead($securityContext))
		{
			return false;
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
					'type' => 'users',
				)
			);
		}
		else
		{
			$entityList = $object->getMembersOfSharing();
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

		if ($realObject instanceof File && $realObject->supportsUnifiedLink())
		{
			$response['unifiedLink'] = $this->getUnifiedLinkData($realObject);
		}

		return $response;
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

	private function getUnifiedLinkData(File $object): array
	{
		$link = Driver::getInstance()->getUrlManager()->getUnifiedLink($object, [
			'absolute' => true,
		]);

		return [
			'link' => $link,
			'currentAccessLevel' => $this->getUnifiedLinkCurrentAccessLevel($object),
			'availableAccessLevels' => array_map(static fn (UnifiedLinkAccessLevel $accessLevel) => [
				'value' => $accessLevel->value,
				'name' => $accessLevel->getTitle(),
			], UnifiedLinkAccessLevel::cases()),
		];
	}

	private function getUnifiedLinkCurrentAccessLevel(File $object): UnifiedLinkAccessLevel
	{
		$userId = (int)CurrentUser::get()->getId();

		return (new UnifiedLinkAccessCheckHandler($userId))->check($object);
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
		File|Folder $object,
		Disk\Security\SecurityContext $securityContext,
	)
	{
		Driver::getInstance()->getTrackedObjectManager()->refresh($object);

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

	/**
	 * @param BaseObject $object
	 * @return void
	 */
	private function terminateSessionsWithInsufficientRights(BaseObject $object): void
	{
		$sessionTerminationService = (new Disk\Document\SessionTerminationServiceFactory($object))->create();
		$sessionTerminationService->terminateSessionsWithInsufficientRights();
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

	protected function checkManageExternalLink(File $file, bool $onlyRead): ?ExternalLinkDisableReason
	{
		$isBoardType = (int)$file->getTypeFile() === TypeFile::BOARD;
		$featureName = $isBoardType ? 'disk_board_external_link' : 'disk_manual_external_link';

		if (!Bitrix24Manager::isFeatureEnabled($featureName))
		{
			return ExternalLinkDisableReason::Feature;
		}

		if (!Configuration::isPossibleToShowExternalLinkControl())
		{
			return ExternalLinkDisableReason::Option;
		}

		if ($onlyRead)
		{
			$objectOptions = $file->getObjectOptions();

			$allowManagePublicAccessWithViewingRights =
				$objectOptions[ObjectOptionsTable::NAME_ALLOW_MANAGE_PUBLIC_ACCESS_ON_READ]
			;

			if (!$allowManagePublicAccessWithViewingRights)
			{
				return ExternalLinkDisableReason::FileOption;
			}
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
