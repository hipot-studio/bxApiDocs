<?php

namespace Bitrix\Disk;


use Bitrix\Disk\Controller\DocumentService;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Integration\Collab\CollabService;
use Bitrix\Disk\Internal\Service\Document\OfficeEngineRecognizer;
use Bitrix\Disk\Internal\Service\UploadCleanup\MarkerService;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Internals\SharingTable;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

final class Sharing extends Internals\Model
{
	const ERROR_EMPTY_USER_ID               = 'DISK_SHARING_22002';
	const ERROR_EMPTY_REAL_OBJECT           = 'DISK_SHARING_22003';
	const ERROR_COULD_NOT_FIND_USER_STORAGE = 'DISK_SHARING_22004';
	const ERROR_COULD_NOT_FIND_STORAGE      = 'DISK_SHARING_22005';
	const ERROR_COULD_NOT_CREATE_LINK       = 'DISK_SHARING_22006';

	/** @deprecated Instead use: {@see \Bitrix\Disk\Access\AccessCodeEnum::USER}  */
	const CODE_USER         = 'U';

	/** @deprecated Instead use: {@see \Bitrix\Disk\Access\AccessCodeEnum::GROUP}  */
	const CODE_GROUP        = 'G';

	/** @deprecated Instead use: {@see \Bitrix\Disk\Access\AccessCodeEnum::SOCNET_GROUP}  */
	const CODE_SOCNET_GROUP = 'SG';

	/** @deprecated Instead use: {@see \Bitrix\Disk\Access\AccessCodeEnum::DEPARTMENT}  */
	const CODE_DEPARTMENT   = 'DR';
	const CODE_CHAT         = 'CHAT';

	const STATUS_IS_UNREPLIED = SharingTable::STATUS_IS_UNREPLIED;
	const STATUS_IS_APPROVED  = SharingTable::STATUS_IS_APPROVED;
	const STATUS_IS_DECLINED  = SharingTable::STATUS_IS_DECLINED;

	const TYPE_TO_USER       = SharingTable::TYPE_TO_USER;
	const TYPE_TO_GROUP      = SharingTable::TYPE_TO_GROUP;
	const TYPE_TO_DEPARTMENT = SharingTable::TYPE_TO_DEPARTMENT;
	const TYPE_TO_CHAT       = SharingTable::TYPE_TO_CHAT;

	/** @var int */
	protected $parentId;
	/** @var Sharing */
	protected $parent;
	/** @var array */
	protected $children;
	/** @var int */
	protected $createdBy;
	/** @var User */
	protected $createUser;
	/** @var string */
	protected $toEntity;
	/** @var string */
	protected $fromEntity;
	/** @var int */
	protected $linkObjectId;
	/** @var int */
	protected $linkStorageId;
	/** @var BaseObject */
	protected $linkObject;
	/** @var Storage */
	protected $linkStorage;
	/** @var int */
	protected $realObjectId;
	/** @var int */
	protected $realStorageId;
	/** @var BaseObject */
	protected $realObject;
	/** @var Storage */
	protected $realStorage;
	/** @var string */
	protected $description;
	/** @var bool */
	protected $canForward;
	/** @var int */
	protected $type;
	/** @var int */
	protected $status;
	/** @var string */
	protected $taskName;

	/**
	 * Gets the fully qualified name of table class which belongs to current model.
	 * @throws \Bitrix\Main\NotImplementedException
	 * @return string
	 */
	public static function getTableClassName()
	{
		return SharingTable::className();
	}

	/**
	 * Adds row to entity table, fills error collection and builds model.
	 * @param array           $data Data.
	 * @param ErrorCollection $errorCollection Error collection.
	 * @return \Bitrix\Disk\Internals\Model|static|null
	 * @throws \Bitrix\Main\NotImplementedException
	 * @internal
	 */
	public static function add(array $data, ErrorCollection $errorCollection)
	{
		$successSharing = static::addToManyEntities($data, array($data['TO_ENTITY'] => $data['TASK_NAME']), $errorCollection);

		if($successSharing === null || !isset($successSharing[$data['TO_ENTITY']]))
		{
			return null;
		}

		return $successSharing[$data['TO_ENTITY']];
	}

	public static function hasRightToKnowAboutEntity(int $userId, string $sharingEntity): bool
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return true;
		}

		[$entityType, $entityId] = self::parseEntityValue($sharingEntity);
		if ($entityType === self::TYPE_TO_USER)
		{
			return \CSocNetUser::canProfileView($userId, $entityId);
		}
		elseif ($entityType === self::TYPE_TO_GROUP)
		{
			$result = \CSocNetGroup::getList(
				[],
				[
					'ID' => $entityId,
					'CHECK_PERMISSIONS' => $userId,
				],
				false,
				false,
				['ID']
			);

			$result = $result ? $result->fetch() : [];

			return $result && !empty($result['ID']);
		}

		return true;
	}

	/**
	 * Adds sharing to many entities.
	 * @param array           $data Data.
	 * @param array           $entitiesToTask Array of entities.
	 * @param ErrorCollection $errorCollection Error collection.
	 * @return Sharing[]
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function addToManyEntities(
		array $data,
		array $entitiesToTask,
		ErrorCollection $errorCollection,
		?AuditTransaction $auditTransaction = null,
	)
	{
		self::checkRequiredInputParams($data, array('FROM_ENTITY', 'CREATED_BY'));

		if(isset($data['REAL_OBJECT']) && $data['REAL_OBJECT'] instanceof BaseObject)
		{
			/** @noinspection PhpUndefinedMethodInspection */
			$data['REAL_OBJECT_ID'] = $data['REAL_OBJECT']->getId();
		}
		elseif(isset($data['REAL_OBJECT_ID']))
		{
			$data['REAL_OBJECT'] = BaseObject::loadById($data['REAL_OBJECT_ID']);
		}
		else
		{
			$errorCollection->addOne(
				new Error(Loc::getMessage('DISK_SHARING_MODEL_ERROR_EMPTY_REAL_OBJECT'), self::ERROR_EMPTY_REAL_OBJECT)
			);
			return null;
		}
		/** @var \Bitrix\Disk\BaseObject $objectToSharing */
		$objectToSharing = $data['REAL_OBJECT'];

		$entitiesToTask = static::deletePossibleCycleFromEntities($objectToSharing, $entitiesToTask);

		//resolve to last real object. In table we write only real (not link) id.
		$objectToSharing = $objectToSharing->getRealObject();
		$data['REAL_OBJECT_ID'] = $objectToSharing->getId();
		$data['REAL_STORAGE_ID'] = $objectToSharing->getStorageId();
		$markerService = new MarkerService();
		if (!$markerService->revokeBeforeUse(
			(int)$objectToSharing->getId(),
			$objectToSharing->getUploadCleanupMarkerPresenceHint(),
		))
		{
			$errorCollection->addOne(new Error(MarkerService::ERROR_FILE_IS_BEING_REMOVED));

			return null;
		}

		$dataToInsert = $data;
		unset($dataToInsert['REAL_OBJECT']);

		//we don't have to connect object, which already exists in same storage
		$ownerUserId = null;
		if($objectToSharing->getStorage()->getProxyType() instanceof ProxyType\User)
		{
			$ownerUserId = $objectToSharing->getStorage()->getEntityId();
		}

		$rightManager = Driver::getInstance()->getRightsManager();
		/** @var Sharing[] $successSharingByEntity */
		$successSharingByEntity = array();
		foreach ($entitiesToTask as $entity => $taskName)
		{
			[$type, $id] = self::parseEntityValue($entity);
			if(!$type)
			{
				continue;
			}

			if ($type == SharingTable::TYPE_TO_USER && $id == $ownerUserId)
			{
				continue;
			}

			$dataToInsert['TO_ENTITY'] = $entity;
			$dataToInsert['TYPE'] = $type;
			$dataToInsert['TASK_NAME'] = $taskName;
			if($type == SharingTable::TYPE_TO_DEPARTMENT || $type == SharingTable::TYPE_TO_CHAT)
			{
				$dataToInsert['STATUS'] = SharingTable::STATUS_IS_APPROVED;
			}

			$sharingModel = null;
			if ($type == SharingTable::TYPE_TO_USER)
			{
				$sharingModel = self::detachDepartmentChild($dataToInsert, $errorCollection);
				if ($sharingModel === false)
				{
					continue;
				}
			}

			$sharingModel ??= $markerService->runWithConfirmedRevokedMarker(
				(int)$objectToSharing->getId(),
				fn() => parent::add($dataToInsert, $errorCollection),
			);
			if(!$sharingModel)
			{
				continue;
			}

			$successSharingByEntity[$entity] = $sharingModel->setAttributes(array('REAL_OBJECT' => $objectToSharing));
			if($type == SharingTable::TYPE_TO_DEPARTMENT && Loader::includeModule('socialnetwork'))
			{
				unset($dataToInsert['STATUS']);
				//todo expand access code DR to list of users. And for each user of list create Sharing
				$dataToInsertChild = $dataToInsert;
				$dataToInsertChild['PARENT_ID'] = $sharingModel->getId();
				$dataToInsertChild['TYPE'] = SharingTable::TYPE_TO_USER;

				foreach(\CSocNetLogDestination::getDestinationUsers(array($entity)) as $userId)
				{
					if($ownerUserId == $userId)
					{
						continue;
					}

					$dataToInsertChild['TO_ENTITY'] = self::CODE_USER . $userId;

					$sharingModel = $markerService->runWithConfirmedRevokedMarker(
						(int)$objectToSharing->getId(),
						fn() => parent::add($dataToInsertChild, $errorCollection),
					);
					if(!$sharingModel)
					{
						continue;
					}
					//above we can already added sharing to this entity (user) and we should not overwrite that. DR-sharing has lower priority than another.
					if(!isset($successSharingByEntity[$dataToInsertChild['TO_ENTITY']]))
					{
						$successSharingByEntity[$dataToInsertChild['TO_ENTITY']] = $sharingModel->setAttributes(array('REAL_OBJECT' => $objectToSharing,));
					}
				}
				unset($userId);
			}
			elseif($type == SharingTable::TYPE_TO_GROUP)
			{
				//if sharing to socnet group, we should approve invite at once, because it's not personal invite.
				$sharingModel->approve();
			}

		}
		unset($entity, $dataToInsert);

		$forwardTaskId = !empty($data['CAN_FORWARD'])? $rightManager->getTaskIdByName($rightManager::TASK_SHARING) : null;

		$newRights = array();
		foreach ($successSharingByEntity as $entity => $sharingModel)
		{
			if($sharingModel->isToDepartmentChild())
			{
				continue;
			}
			if($sharingModel->isToGroup())
			{
				$entity .= '_K'; //members of group.
			}

			$sharingDomain = $rightManager->getSharingDomain($sharingModel->getId());
			/** @var \Bitrix\Disk\Sharing $sharingModel */
			$newRights[] = array(
				'ACCESS_CODE' => $entity,
				'TASK_ID' => $rightManager->getTaskIdByName($sharingModel->getTaskName()),
				'DOMAIN' => $sharingDomain,
			);
			if($forwardTaskId)
			{
				$newRights[] = array(
					'ACCESS_CODE' => $entity,
					'TASK_ID' => $forwardTaskId,
					'DOMAIN' => $sharingDomain,
				);
			}
		}
		unset($entity);
		$rightsWereAppended = $rightManager->append($objectToSharing, $newRights, $auditTransaction);
		if (!$rightsWereAppended && $auditTransaction !== null)
		{
			$errorCollection->add($rightManager->getErrors());

			return null;
		}

		$connectAndNotify = static function () use ($successSharingByEntity, $objectToSharing): void {
			self::processConnectAndNotify($successSharingByEntity, $objectToSharing);
			self::cleanCache($successSharingByEntity);
		};
		if ($auditTransaction !== null)
		{
			$auditTransaction->addAfterCommit($connectAndNotify);
		}
		else
		{
			$connectAndNotify();
		}

		return $successSharingByEntity;
	}

	/**
	 * A department sharing leaves a child row per employee, and the row blocks a personal sharing to the same user.
	 * Turns such a row into the personal sharing, keeping its status and the already connected link.
	 * @return Sharing|false|null Detached sharing, false on failure, null when there is no child row.
	 */
	private static function detachDepartmentChild(array $data, ErrorCollection $errorCollection): Sharing|false|null
	{
		$child = static::load([
			'=TO_ENTITY' => $data['TO_ENTITY'],
			'=REAL_OBJECT_ID' => $data['REAL_OBJECT_ID'],
			'>PARENT_ID' => 0,
			'!=STATUS' => SharingTable::STATUS_IS_DECLINED,
		]);
		if (!$child)
		{
			return null;
		}

		$updated = $child->update([
			'PARENT_ID' => null,
			'TASK_NAME' => $data['TASK_NAME'],
			'FROM_ENTITY' => $data['FROM_ENTITY'],
			'CREATED_BY' => $data['CREATED_BY'],
		]);
		if (!$updated)
		{
			$errorCollection->add($child->getErrors());

			return false;
		}

		return $child;
	}

	private static function deletePossibleCycleFromEntities(BaseObject $objectToSharing, array $entities)
	{
		$realObject = $objectToSharing->getRealObject();
		if (!$realObject || !$realObject instanceof Folder)
		{
			return $entities;
		}
		/** @var $realObject Folder */
		if (!$realObject->isRoot())
		{
			return $entities;
		}

		if (!$realObject->getStorage()->getProxyType() instanceof ProxyType\Group)
		{
			return $entities;
		}

		$entityCode = 'SG' . $realObject->getStorage()->getEntityId();
		if (isset($entities[$entityCode]))
		{
			unset($entities[$entityCode]);
		}

		return $entities;
	}

	/**
	 * @param Sharing[] $successSharingByEntity
	 */
	private static function cleanCache(array $successSharingByEntity)
	{
		$storageIds = array();
		foreach($successSharingByEntity as $entity => $sharing)
		{
			if($sharing->getFromEntity() === $sharing->getToEntity())
			{
				continue;
			}

			[$type] = Sharing::parseEntityValue($sharing->getFromEntity());
			if($type != SharingTable::TYPE_TO_USER)
			{
				continue;
			}
			$storageIds[$sharing->getRealStorageId()] = $sharing->getRealStorageId();
		}

		$cache = Cache::createInstance();
		foreach($storageIds as $id)
		{
			$cache->clean('storage_isshared_' . $id, 'disk');
		}
	}

	/**
	 * Parses entity code to get entity type and entity id.
	 * Ex. SG444 = array('SG', 444)
	 * @param $entity
	 * @return array|null
	 */
	public static function parseEntityValue($entity)
	{
		$codes = [
			self::CODE_USER,
			self::CODE_SOCNET_GROUP,
			self::CODE_DEPARTMENT,
			self::CODE_CHAT,
		];

		preg_match(
			'%(' . implode('|', $codes) . ')([0-9]+)%u',
			$entity,
			$m
		);

		[, $code, $id] = $m;
		if($code === null || $id === null)
		{
			return null;
		}
		switch($code)
		{
			case self::CODE_USER:
				return array(SharingTable::TYPE_TO_USER, $id);
			case self::CODE_SOCNET_GROUP:
				return array(SharingTable::TYPE_TO_GROUP, $id);
			case self::CODE_DEPARTMENT:
				return array(SharingTable::TYPE_TO_DEPARTMENT, $id);
			case self::CODE_CHAT:
				return array(SharingTable::TYPE_TO_CHAT, $id);
		}
		return null;
	}


	/**
	 * Connects group storage to self storage.
	 * @param int             $userId Id of user.
	 * @param Storage         $storage Storage.
	 * @param ErrorCollection $errorCollection Error collection.
	 * @return Sharing|null
	 */
	public static function connectGroupToSelfUserStorage($userId, Storage $storage, ErrorCollection $errorCollection)
	{
		return self::connectToUserStorage($userId, array(
			'AUTO_CONNECT' => true,
			'CREATED_BY' => $userId,
			'LINK_NAME' => Ui\Text::correctFolderName($storage->getProxyType()->getEntityTitle()),
			'REAL_OBJECT' => $storage->getRootObject(),
		), $errorCollection);
	}

	/**
	 * Connects object to self storage.
	 * @param int             $userId Id of user.
	 * @param BaseObject      $object Target object.
	 * @param ErrorCollection $errorCollection Error collection.
	 * @return Sharing|null
	 */
	public static function connectObjectToSelfUserStorage($userId, BaseObject $object, ErrorCollection $errorCollection)
	{
		return self::connectToUserStorage($userId, array(
			'AUTO_CONNECT' => true,
			'CREATED_BY' => $userId,
			'REAL_OBJECT' => $object,
		), $errorCollection);
	}

	/**
	 * Connects storage to user storage.
	 * @param int             $createdBy Id of user, who create sharing.
	 * @param int             $userId Id of user.
	 * @param Storage         $storage Storage (any type).
	 * @param ErrorCollection $errorCollection Error collection.
	 * @return Sharing|null
	 */
	public static function connectStorageToUserStorage($createdBy, $userId, Storage $storage, ErrorCollection $errorCollection)
	{
		$autoConnect = null;
		$collabService = new CollabService();
		if ($collabService->isCollabStorage($storage))
		{
			$autoConnect = true;
		}

		return self::connectToUserStorage($userId, array_filter([
			'AUTO_CONNECT' => $autoConnect,
			'CREATED_BY' => $createdBy,
			'LINK_NAME' => Ui\Text::correctFolderName($storage->getProxyType()->getEntityTitle()),
			'REAL_OBJECT' => $storage->getRootObject(),
		]), $errorCollection);
	}

	/**
	 * Connects object from [[$data['REAL_OBJECT']]] (or by id from [[$data['REAL_OBJECT_ID']]]) to user storage.
	 *
	 * If set $data['SELF_CONNECT'] to true, then doesn't ask about connecting object.
	 *
	 * @param int             $userId Id of user.
	 * @param array           $data Data.
	 * @param ErrorCollection $errorCollection Error collection.
	 * @return Sharing|null
	 * @throws \Bitrix\Main\NotImplementedException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function connectToUserStorage($userId, array $data, ErrorCollection $errorCollection)
	{
		$storage = Driver::getInstance()->getStorageByUserId($userId);
		if(!$storage)
		{
			$storage = Driver::getInstance()->addUserStorage($userId);
		}
		if(!$storage)
		{
			$errorCollection->addOne(
				new Error(
					Loc::getMessage('DISK_SHARING_MODEL_ERROR_COULD_NOT_FIND_USER_STORAGE',
						array('#USER_ID#' => $userId)
					),
					self::ERROR_COULD_NOT_FIND_USER_STORAGE)
			);
			return null;
		}

		$autoConnect = !empty($data['AUTO_CONNECT']);
		$linkName = !empty($data['LINK_NAME'])? $data['LINK_NAME'] : null;
		unset($data['SELF_CONNECT'], $data['AUTO_CONNECT'], $data['LINK_NAME']);

		$data['TYPE'] = SharingTable::TYPE_TO_USER;
		$data['FROM_ENTITY'] = self::CODE_USER . (int)$userId;
		$data['TO_ENTITY'] = self::CODE_USER . (int)$userId;
		$data['TASK_NAME'] = RightsManager::TASK_READ;

		if(isset($data['REAL_OBJECT']) && $data['REAL_OBJECT'] instanceof BaseObject)
		{
			/** @noinspection PhpUndefinedMethodInspection */
			$data['REAL_OBJECT_ID'] = $data['REAL_OBJECT']->getId();
		}
		elseif(isset($data['REAL_OBJECT_ID']))
		{
			$data['REAL_OBJECT'] = BaseObject::loadById($data['REAL_OBJECT_ID']);
		}
		else
		{
			$errorCollection->addOne(
				new Error(Loc::getMessage('DISK_SHARING_MODEL_ERROR_EMPTY_REAL_OBJECT'), self::ERROR_EMPTY_REAL_OBJECT)
			);
			return null;
		}
		/** @var \Bitrix\Disk\BaseObject $objectToSharing */
		$objectToSharing = $data['REAL_OBJECT'];
		//resolve to last real object. In table we write only real (not link) id.
		$objectToSharing = $objectToSharing->getRealObject();
		if (!$objectToSharing)
		{
			return null;
		}
		$data['REAL_OBJECT_ID'] = $objectToSharing->getId();
		$data['REAL_STORAGE_ID'] = $objectToSharing->getStorageId();

		$dataToInsert = $data;
		unset($dataToInsert['REAL_OBJECT']);

		$sharingModel = parent::add($dataToInsert, $errorCollection);
		if(!$sharingModel)
		{
			return null;
		}
		$sharingModel->setAttributes(array('REAL_OBJECT' => $objectToSharing));

		if(!$autoConnect)
		{
			self::processConnectAndNotify(array($sharingModel), $objectToSharing);
		}
		else
		{
			if(!$sharingModel->approve(array('LINK_NAME' => $linkName)))
			{
				$errorCollection->add($sharingModel->getErrors());
				$sharingModel->delete($userId);
				return null;
			}
		}

		return $sharingModel;
	}

	/**
	 * Tells if sharing object is connected to user storage.
	 *
	 * @param int                   $userId Id of user.
	 * @param BaseObject|BaseObject $object
	 * @param array                 &$returnData Special parameter for optimization it fills by fields.
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public static function isConnectedToUserStorage($userId, BaseObject $object, array &$returnData = array())
	{
		$userId = (int)$userId;
		$returnData = static::getList(array(
			'select' => array('REAL_OBJECT_ID', 'LINK_OBJECT_ID'),
			'filter' => array(
				'REAL_OBJECT_ID' => $object->getRealObjectId(),
				'=TO_ENTITY' => self::CODE_USER . $userId,
				'=STATUS' => SharingTable::STATUS_IS_APPROVED,
			),
			'limit' => 1,
		))->fetch();

		return (bool)$returnData;
	}

	/**
	 * @param $userId
	 * @param BaseObject $object
	 *
	 * @return Sharing|null
	 */
	public static function getExisting($userId, BaseObject $object)
	{
		$userId = (int)$userId;
		$sharings = static::getModelList(array(
			'filter' => array(
				'REAL_OBJECT_ID' => $object->getRealObjectId(),
				'=TO_ENTITY' => self::CODE_USER . $userId,
				'@STATUS' => array(
					SharingTable::STATUS_IS_UNREPLIED,
					SharingTable::STATUS_IS_APPROVED,
				),
			),
			'limit' => 1,
		));

		return array_shift($sharings)?: null;
	}

	private static function generateLinkToViewDocument(Sharing $sharingModel, BaseObject $objectToSharing): ?string
	{
		$documentHandler = Driver::getInstance()->getDocumentHandlersManager()->getDefaultHandlerForView();
		if (!OfficeEngineRecognizer::isOfficeEngine($documentHandler))
		{
			return null;
		}

		if (!($objectToSharing instanceof File))
		{
			return null;
		}

		if (!DocumentHandler::isEditable($objectToSharing->getExtension()))
		{
			return null;
		}

		if (
			($sharingModel->getLinkObject() instanceof FileLink)
			&& $sharingModel->getLinkObject()->getTypeFile() == TypeFile::FLIPCHART
		)
		{
			// $uri = Driver::getInstance()->getUrlManager()->getUrlForViewBoard($sharingModel->getLinkObjectId(),false, 'disk_page');
			$uri = self::getBoardLink($sharingModel->getLinkObject());
		}
		else
		{
			$uri = (new DocumentService())->getActionUri('goToEditOrPreview', [
				'serviceCode' => 'onlyoffice',
				'objectId' => $sharingModel->getLinkObjectId(),
			]);
		}

		return (string)$uri;
	}

	/**
	 * @param Sharing[]                 $successSharingByEntity
	 * @param File|Folder|BaseObject $objectToSharing
	 */
	private static function processConnectAndNotify(array $successSharingByEntity, BaseObject $objectToSharing)
	{
		$isFolder = $objectToSharing instanceof Folder;
		$isBoard = !$isFolder && $objectToSharing->getRealObject()->getTypeFile() == TypeFile::FLIPCHART;
		if(Configuration::canAutoconnectSharedObjects())
		{
			foreach($successSharingByEntity as $entity => $sharingModel)
			{
				/** @var \Bitrix\Disk\Sharing $sharingModel */
				if(!$sharingModel->approve())
				{
					unset($successSharingByEntity[$entity]);
				}
				else
				{
					if(!$sharingModel->isToUser())
					{
						continue;
					}

					[$subTag, $tag] = $sharingModel->getNotifyTags();
					Driver::getInstance()->sendNotify(
						mb_substr($sharingModel->getToEntity(), 1),
						[
							'FROM_USER_ID' => $sharingModel->getCreatedBy(),
							'NOTIFY_EVENT' => 'sharing_autoconnect',
							'NOTIFY_TAG' => $tag,
							'NOTIFY_SUB_TAG' => $subTag,
						]
						+ self::buildConnectNotifyMessages(
							$sharingModel,
							$objectToSharing,
							(string)$sharingModel->getDescription(),
							true,
						),
					);
				}
			}
		}
		else
		{
			$fileApproveButton = $isBoard
				? 'DISK_SHARING_MODEL_APPROVE_Y_BOARD'
				: 'DISK_SHARING_MODEL_APPROVE_Y_FILE';
			$fileApproveMessage = $isBoard
				? 'DISK_SHARING_MODEL_TEXT_APPROVE_CONFIRM_BOARD'
				: 'DISK_SHARING_MODEL_TEXT_APPROVE_CONFIRM_FILE';
			$objectToSharingName = $isBoard
				? $objectToSharing->getNameWithoutExtension()
				: $objectToSharing->getName();

			$buttons = array(
				array(
					'TITLE' => Loc::getMessage($isFolder ? 'DISK_SHARING_MODEL_APPROVE_Y' : $fileApproveButton),
					'VALUE' => 'Y',
					'TYPE' => 'accept'
				),
				array(
					'TITLE' => Loc::getMessage('DISK_SHARING_MODEL_APPROVE_N_2_DECLINE'),
					'VALUE' => 'N',
					'TYPE' => 'cancel'
				)
			);
			$message = Loc::getMessage($isFolder ? 'DISK_SHARING_MODEL_TEXT_APPROVE_CONFIRM' : $fileApproveMessage, array(
				'#NAME#' => $objectToSharingName,
			));

			foreach($successSharingByEntity as $entity => $sharingModel)
			{
				// a detached department child may be connected already, it must not be invited again
				if(!$sharingModel->isToUser() || ($sharingModel->isApproved() && $sharingModel->getLinkObjectId()))
				{
					continue;
				}
				[$subTag, $tag] = $sharingModel->getNotifyTags();
				Driver::getInstance()->sendNotify(mb_substr($sharingModel->getToEntity(), 1), array(
					'NOTIFY_BUTTONS' => $buttons,
					'NOTIFY_TYPE' => 'IM_NOTIFY_CONFIRM',
					'FROM_USER_ID' => $sharingModel->getCreatedBy(),
					'NOTIFY_EVENT' => 'sharing',
					'NOTIFY_TAG' => $tag,
					'NOTIFY_SUB_TAG' => $subTag,
					'NOTIFY_MESSAGE' => $message,
					'NOTIFY_MESSAGE_OUT' => strip_tags($message),
				));
			}
		}
	}

	/**
	 * Tells if sharing can forwarding by user.
	 * @return boolean
	 */
	public function isCanForward()
	{
		return $this->canForward;
	}

	/**
	 * Returns description.
	 * @return string
	 */
	public function getDescription()
	{
		return $this->description;
	}

	/**
	 * Tells if sharing is approved.
	 * @return boolean
	 */
	public function isApproved()
	{
		return $this->status == SharingTable::STATUS_IS_APPROVED;
	}

	/**
	 * Tells if sharing is declined.
	 * @return boolean
	 */
	public function isDeclined()
	{
		return $this->status == SharingTable::STATUS_IS_DECLINED;
	}

	/**
	 * Tells if sharing is unreplied.
	 * @return boolean
	 */
	public function isUnreplied()
	{
		return $this->status == SharingTable::STATUS_IS_UNREPLIED;
	}

	/**
	 * Tells if sharing is to user.
	 * @return boolean
	 */
	public function isToUser()
	{
		return $this->type == SharingTable::TYPE_TO_USER;
	}

	/**
	 * Tells if sharing is to group.
	 * @return boolean
	 */
	public function isToGroup()
	{
		return $this->type == SharingTable::TYPE_TO_GROUP;
	}

	/**
	 * Tells if sharing is to department.
	 * @return boolean
	 */
	public function isToDepartment()
	{
		return $this->type == SharingTable::TYPE_TO_DEPARTMENT;
	}

	/**
	 * Tells if sharing is to department and it is parent sharing.
	 * @return boolean
	 */
	public function isToDepartmentParent()
	{
		return !$this->parentId && $this->isToDepartment();
	}

	/**
	 * Tells if sharing is to department and it is child sharing.
	 * The sharing is born by parent sharing.
	 * @return boolean
	 */
	public function isToDepartmentChild()
	{
		//todo Now I dont know what child sharing on user of department will be with type TYPE_TO_USER, or with TO_DEPARTMENT
		return $this->parentId && $this->isToUser();
	}

	/**
	 * Returns task name.
	 * @return string
	 */
	public function getTaskName()
	{
		return $this->taskName;
	}

	/**
	 * Returns link object id.
	 * @return int
	 */
	public function getLinkObjectId()
	{
		return $this->linkObjectId;
	}

	/**
	 * Returns parent id.
	 * @return int
	 */
	public function getParentId()
	{
		return $this->parentId;
	}

	/**
	 * Returns parent sharing.
	 * @return Sharing|null
	 */
	public function getParent()
	{
		if(!$this->parentId)
		{
			return null;
		}

		if(isset($this->parent) && $this->parentId === $this->parent->getId())
		{
			return $this->parent;
		}
		$this->parent = Sharing::loadById($this->parentId);

		return $this->parent;
	}

	/**
	 * Returns created user id.
	 * @return int
	 */
	public function getCreatedBy()
	{
		return $this->createdBy;
	}

	/**
	 * Returns created user model.
	 * @return User
	 */
	public function getCreateUser()
	{
		if(isset($this->createUser) && $this->createdBy == $this->createUser->getId())
		{
			return $this->createUser;
		}
		$this->createUser = User::getModelForReferenceField($this->createdBy, $this->createUser);

		return $this->createUser;
	}

	/**
	 * Returns entity.
	 * @return string
	 */
	public function getToEntity()
	{
		return $this->toEntity;
	}

	/**
	 * Returns entity.
	 * @return string
	 */
	public function getFromEntity()
	{
		return $this->fromEntity;
	}

	/**
	 * Returns link storage id.
	 * @return int
	 */
	public function getLinkStorageId()
	{
		return $this->linkStorageId;
	}

	/**
	 * Returns link storage model.
	 * @return Storage|null
	 */
	public function getLinkStorage()
	{
		if(!$this->linkStorageId)
		{
			return null;
		}

		if(isset($this->linkStorage) && $this->linkStorageId === $this->linkStorage->getId())
		{
			return $this->linkStorage;
		}
		$this->linkStorage = Storage::loadById($this->linkStorageId);

		return $this->linkStorage;
	}

	/**
	 * Returns real storage id.
	 * @return int
	 */
	public function getRealStorageId()
	{
		return $this->realStorageId;
	}

	/**
	 * Returns real storage model.
	 * @return Storage|null
	 */
	public function getRealStorage()
	{
		if(!$this->realStorageId)
		{
			return null;
		}

		if(isset($this->realStorage) && $this->realStorageId === $this->realStorage->getId())
		{
			return $this->realStorage;
		}
		$this->realStorage = Storage::loadById($this->realStorageId);

		return $this->realStorage;
	}

	/**
	 * Returns link object model.
	 * @return null|\Bitrix\Disk\FileLink|\Bitrix\Disk\FolderLink
	 */
	public function getLinkObject()
	{
		if(!$this->linkObjectId)
		{
			return null;
		}

		if(isset($this->linkObject) && $this->linkObjectId === $this->linkObject->getId())
		{
			return $this->linkObject;
		}
		$this->linkObject = BaseObject::loadById($this->linkObjectId);

		return $this->linkObject;
	}

	/**
	 * Returns real object model.
	 * @return BaseObject
	 */
	public function getRealObject()
	{
		if(!$this->realObjectId)
		{
			return null;
		}

		if(isset($this->realObject) && $this->realObjectId === $this->realObject->getId())
		{
			return $this->realObject;
		}
		$this->realObject = BaseObject::loadById($this->realObjectId);

		return $this->realObject;
	}

	/**
	 * Returns link object id.
	 * @return int
	 */
	public function getRealObjectId()
	{
		return $this->realObjectId;
	}

	/**
	 * @return Storage|null
	 * @throws \Bitrix\Main\SystemException
	 */
	private function getTargetStorageByToEntity()
	{
		if(!$this->toEntity)
		{
			return null;
		}
		switch($this->type)
		{
			case SharingTable::TYPE_TO_USER:
				if(mb_substr($this->toEntity, 0, 1) !== self::CODE_USER)
				{
					return null;
				}
				$userId = (int)mb_substr($this->toEntity, 1);
				$storage = Driver::getInstance()->getStorageByUserId($userId);
				if(!$storage)
				{
					$storage = Driver::getInstance()->addUserStorage($userId);
				}
				return $storage;
			case SharingTable::TYPE_TO_GROUP:
				if(mb_substr($this->toEntity, 0, 2) !== self::CODE_SOCNET_GROUP)
				{
					return null;
				}
				return Driver::getInstance()->getStorageByGroupId((int)mb_substr($this->toEntity, 2));
		}
		return null;
	}

	/**
	 * Changes task name of sharing.
	 * @param string $newTaskName New task name.
	 * @return bool
	 */
	public function changeTaskName($newTaskName)
	{
		if($this->taskName === $newTaskName)
		{
			return true;
		}

		$success = $this->update(array(
			'TASK_NAME' => $newTaskName,
		));
		if(!$success)
		{
			return false;
		}
		if($this->isToDepartmentParent())
		{
			SharingTable::updateBatch(array('TASK_NAME' => $newTaskName,), array('PARENT_ID' => $this->id));
			if($this->isLoadedChildren())
			{
				foreach($this->getChildren() as $child)
				{
					$child->setAttributes(array('TASK_NAME' => $newTaskName,));
				}
				unset($child);
			}
		}

		return true;
	}

	/**
	 * Attaches link after approving sharing.
	 * @param array $data Data.
	 * @return bool
	 */
	public function approve(array $data = array())
	{
		$this->errorCollection->clear();

		if($this->isApproved())
		{
			return true;
		}

		if($this->isDeclined())
		{
			return false;
		}

		if($this->isToDepartmentParent())
		{
			return true;
		}

		$targetStorage = $this->getTargetStorageByToEntity();
		if(!$targetStorage)
		{
			$this->errorCollection->addOne(
				new Error(
					Loc::getMessage('DISK_SHARING_MODEL_ERROR_COULD_NOT_FIND_STORAGE'),
					self::ERROR_COULD_NOT_FIND_STORAGE
				)
			);
			return false;
		}

		$realObject = $this->getRealObject();
		if (!$realObject || !$realObject->getStorage())
		{
			$this->errorCollection[] = new Error('Could not find real object');

			return false;
		}

		$linkModel = null;
		if($this->isToUser() || $this->isToGroup() || $this->isToDepartmentChild())
		{
			$linkName = empty($data['LINK_NAME'])? null : $data['LINK_NAME'];
			if(
				!$linkName &&
				$realObject->isRoot() &&
				$realObject->getStorage()->getProxyType() instanceof ProxyType\Group
			)
			{
				$linkName = Ui\Text::correctFolderName($realObject->getStorage()->getProxyType()->getEntityTitle());
			}

			if($realObject instanceof Folder)
			{
				$linkModel = $targetStorage->addFolderLink($realObject, array(
					'NAME' => $linkName,
					'CREATED_BY' => $this->createdBy
				), array(), true);
			}
			elseif($realObject instanceof File)
			{
				$linkModel = $targetStorage->addFileLink($realObject, array(
					'NAME' => $linkName,
					'CREATED_BY' => $this->createdBy
				), array(), true);
			}
		}

		if(!$linkModel)
		{
			$this->errorCollection->addOne(
				new Error(
					Loc::getMessage('DISK_SHARING_MODEL_ERROR_COULD_NOT_CREATE_LINK'),
					self::ERROR_COULD_NOT_CREATE_LINK
				)
			);
			$this->errorCollection->add($targetStorage->getErrors());
			return false;
		}

		$success = $this->update(array(
			'LINK_OBJECT_ID' => $linkModel->getId(),
			'LINK_STORAGE_ID' => $linkModel->getStorageId(),
			'STATUS' => SharingTable::STATUS_IS_APPROVED,
		));
		if(!$success)
		{
			return false;
		}
		$this->linkObject = $linkModel;

		[, $tag] = $this->getNotifyTags();
		if(Loader::includeModule('im'))
		{
			\CIMNotify::deleteByTag($tag);
		}


		return true;
	}

	/**
	 * Declines sharing.
	 * @param int  $declinedBy Id of user.
	 * @param bool $withDeletingObject whether to delete objects, which were created by sharing models.
	 * @return bool
	 */
	public function decline(
		$declinedBy,
		$withDeletingObject = true,
		?AuditTransaction $auditTransaction = null,
	)
	{
		$this->errorCollection->clear();

		if($this->isDeclined())
		{
			return true;
		}

		if(
			$withDeletingObject &&
			($this->isToUser() || $this->isToGroup() || $this->isToDepartmentChild())
		)
		{
			$linkModel = $this->getLinkObject();
			if($linkModel instanceof FolderLink)
			{
				$linkModel->deleteTree($declinedBy);
			}
			elseif($linkModel instanceof FileLink)
			{
				$linkModel->deleteWithoutSharing($declinedBy);
			}
		}

		$declinedAttributes = array(
			'LINK_OBJECT_ID' => null,
			'LINK_STORAGE_ID' => null,
			'STATUS' => SharingTable::STATUS_IS_DECLINED,
		);
		if ($auditTransaction !== null)
		{
			SharingTable::updateBatch($declinedAttributes, ['ID' => $this->id]);
			$this->setAttributes($declinedAttributes);
		}
		elseif (!$this->update($declinedAttributes))
		{
			return false;
		}

		foreach($this->getChildren() as $childSharing)
		{
			$childDeclined = $childSharing->decline($declinedBy, $withDeletingObject, $auditTransaction);
			if (!$childDeclined && $auditTransaction !== null)
			{
				$this->errorCollection->add($childSharing->getErrors());

				return false;
			}
		}
		unset($childSharing);

		if(!$this->getRealObject())
		{
			return true;
		}

		if($this->isToUser() || $this->isToGroup() || $this->isToDepartmentParent())
		{
			$rightsManager = Driver::getInstance()->getRightsManager();
			$rightsWereDeleted = $rightsManager->deleteByDomain(
				$this->getRealObject(),
				$rightsManager->getSharingDomain($this->id),
				$auditTransaction,
			);
			if (!$rightsWereDeleted && $auditTransaction !== null)
			{
				$this->errorCollection->add($rightsManager->getErrors());

				return false;
			}

			[, $tag] = $this->getNotifyTags();
			if(Loader::includeModule('im'))
			{
				$deleteNotification = static fn(): mixed => \CIMNotify::deleteByTag($tag);
				if ($auditTransaction !== null)
				{
					$auditTransaction->addAfterCommit($deleteNotification);
				}
				else
				{
					$deleteNotification();
				}
			}
		}

		if(
			$this->isToUser() &&
			!$this->isToDepartmentChild() &&
			self::CODE_USER . $declinedBy === $this->toEntity &&
			$this->fromEntity !== $this->toEntity
		)
		{
			$isFolder = $this->getRealObject() instanceof Folder;
			$isBoard  = !$isFolder && $this->getRealObject()?->getTypeFile() == TypeFile::FLIPCHART;

			$objectName = $this->getRealObject()->getName();
			$disconnectMessageText = 'DISK_SHARING_MODEL_TEXT_SELF_DISCONNECT_FILE';
			if ($isFolder)
			{
				$disconnectMessageText = 'DISK_SHARING_MODEL_TEXT_SELF_DISCONNECT';
			}
			elseif ($isBoard)
			{
				try
				{
					$user = User::loadById($declinedBy);
					$gender = $user->getPersonalGender();
				}
				catch (\Exception)
				{
					$gender = 'M';
				}
				$objectName = $this->getRealObject()->getNameWithoutExtension();
				$disconnectMessageText = 'DISK_SHARING_MODEL_TEXT_SELF_DISCONNECT_BOARD_' . $gender;
			}

			$message = Loc::getMessage(
				$disconnectMessageText,
				array(
					'#NAME#' => $objectName,
					'#USERNAME#' => User::loadById($declinedBy)->getFormattedName(),
				)
			);
			[$subTag, $tag] = $this->getNotifyTags();
			$createdBy = $this->createdBy;
			$notification = array(
				'FROM_USER_ID' => $declinedBy,
				'NOTIFY_EVENT' => 'sharing_user_disconnect',
				'NOTIFY_TAG' => $tag,
	//			'NOTIFY_SUB_TAG' => $subTag,
				'NOTIFY_MESSAGE' => $message,
				'NOTIFY_MESSAGE_OUT' => strip_tags($message),
			);
			$sendNotification = static function () use ($createdBy, $notification): void {
				Driver::getInstance()->sendNotify($createdBy, $notification);
			};
			if ($auditTransaction !== null)
			{
				$auditTransaction->addAfterCommit($sendNotification);
			}
			else
			{
				$sendNotification();
			}
		}

		return true;
	}

	/**
	 * Declines sharing.
	 * @see decline()
	 * @param int $disprovedBy Id of user.
	 * @param bool $withDeletingObject whether to delete objects, which were created by sharing models.
	 * @return bool
	 */
	public function disprove($disprovedBy, $withDeletingObject = true)
	{
		return $this->decline($disprovedBy, $withDeletingObject);
	}

	/**
	 * Deletes sharing.
	 *
	 * If sharing has status declined or unreplied, then first runs decline() and after run delete.
	 *
	 * @param int $deletedBy Id of user.
	 * @param bool $withDeletingObject whether to delete objects, which were created by sharing models.
	 * @return bool
	 */
	public function delete(
		$deletedBy,
		$withDeletingObject = true,
		?AuditTransaction $auditTransaction = null,
	)
	{
		$this->errorCollection->clear();

		if($this->isDeclined())
		{
			return $this->deleteInternal();
		}
		elseif($this->isApproved() || $this->isUnreplied())
		{
			if(!$this->decline($deletedBy, $withDeletingObject, $auditTransaction))
			{
				return false;
			}

			return $this->delete($deletedBy, $withDeletingObject, $auditTransaction);
		}

		return false;
	}

	/**
	 * @return bool
	 */
	protected function deleteInternal()
	{
		foreach($this->getChildren() as $childSharing)
		{
			$childSharing->deleteInternal();
		}
		unset($childSharing);

		return parent::deleteInternal();
	}

	/**
	 * Tells if children sharings is loaded.
	 * @return bool
	 */
	public function isLoadedChildren()
	{
		return $this->children !== null;
	}

	/**
	 * Returns children sharings.
	 * @return Sharing[]
	 */
	public function getChildren()
	{
		if(!$this->isToDepartmentParent())
		{
			return array();
		}
		if(isset($this->children))
		{
			return $this->children;
		}

		$this->children = $this->getModelList(array('filter' => array(
			'PARENT_ID' => $this->getId(),
			'REAL_OBJECT_ID' => $this->getRealObjectId(),
			'REAL_STORAGE_ID' => $this->getRealStorageId(),
			'TYPE' => SharingTable::TYPE_TO_USER,
		)));

		return $this->children;
	}

	/**
	 * Returns the list of pair for mapping data and object properties.
	 * Key is field in DataManager, value is object property.
	 * @return array
	 */
	public static function getMapAttributes()
	{
		return array(
			'ID' => 'id',
			'PARENT_ID' => 'parentId',
			'PARENT' => 'parent',

			'CREATED_BY' => 'createdBy',
			'CREATE_USER' => 'createUser',

			'FROM_ENTITY' => 'fromEntity',
			'TO_ENTITY' => 'toEntity',

			'LINK_OBJECT_ID' => 'linkObjectId',
			'LINK_STORAGE_ID' => 'linkStorageId',

			'LINK_OBJECT' => 'linkObject',
			'LINK_STORAGE' => 'linkStorage',

			'REAL_OBJECT_ID' => 'realObjectId',
			'REAL_STORAGE_ID' => 'realStorageId',

			'REAL_OBJECT' => 'realObject',
			'REAL_STORAGE' => 'realStorage',

			'DESCRIPTION' => 'description',
			'CAN_FORWARD' => 'canForward',
			'TYPE' => 'type',
			'STATUS' => 'status',
			'TASK_NAME' => 'taskName',
		);
	}

	/**
	 * Returns the list attributes which is connected with another models.
	 * @return array
	 */
	public static function getMapReferenceAttributes()
	{
		$storageClassName = Storage::className();
		$objectClassName = BaseObject::className();

		return array(
			'PARENT' => static::className(),
			'LINK_OBJECT' => $objectClassName,
			'REAL_OBJECT' => $objectClassName,
			'LINK_STORAGE' => $storageClassName,
			'REAL_STORAGE' => $storageClassName,
			'CREATE_USER' => array(
				'class' => User::className(),
				'select' => User::getFieldsForSelect(),
			),
		);
	}

	/**
	 * Catches event from IM module, when user click on buttons in notify.
	 * @param string $module Module.
	 * @param string $tag Tag.
	 * @param string $value Value.
	 * @param mixed $notify Notify.
	 * @return void
	 */
	public static function onBeforeConfirmNotify($module, $tag, $value, $notify)
	{
		global $USER;
		if (!$USER instanceof \CUser)
		{
			return;
		}

		$userId = $USER->getId();
		if ( !($module === Driver::INTERNAL_MODULE_ID && $userId) )
		{
			return;
		}
		$sharingModel = static::loadByNotifyTag($tag);

		if(!$sharingModel || !$sharingModel->getRealObject())
		{
			return;
		}

		if(!$sharingModel->isToUser())
		{
			return;
		}

		if($sharingModel->getToEntity() !== self::CODE_USER . $userId)
		{
			return;
		}

		[, $tag] = $sharingModel->getNotifyTags();
		\CIMNotify::deleteByTag($tag);

		if($value === 'N')
		{
			$sharingModel->decline($userId);
			return;
		}

		if($sharingModel->approve())
		{
			[$subTag, $tag] = $sharingModel->getNotifyTags();
			Driver::getInstance()->sendNotify(
				mb_substr($sharingModel->getToEntity(), 1),
				[
					'FROM_USER_ID' => $sharingModel->getCreatedBy(),
					'NOTIFY_EVENT' => 'sharing_autoconnect',
					'NOTIFY_TAG' => $tag,
					'NOTIFY_SUB_TAG' => $subTag,
				]
				+ self::buildConnectNotifyMessages($sharingModel, $sharingModel->getLinkObject()),
			);
		}
	}

	/**
	 * @return array
	 */
	private function getNotifyTags()
	{
		return array(
			Driver::INTERNAL_MODULE_ID . "|SHARING|{$this->realObjectId}",
			Driver::INTERNAL_MODULE_ID . "|SHARING|{$this->realObjectId}|{$this->toEntity}",
		);
	}

	/**
	 * @param $tag
	 * @return null|static
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 * @internal
	 */
	private static function loadByNotifyTag($tag)
	{
		$tagData = explode('|', $tag);
		if( !(
			$tagData[0] === Driver::INTERNAL_MODULE_ID &&
			$tagData[1] === 'SHARING' &&
			isset($tagData[2], $tagData[3]) && is_numeric($tagData[2])
		))
		{
			return null;
		}

		$model = static::load(array(
			'REAL_OBJECT_ID' => (int)$tagData[2],
			'=TO_ENTITY' => $tagData[3],
		));

		if(!$model)
		{
			return null;
		}

		return $model;
	}

	/**
	 * @return array{NOTIFY_MESSAGE: string, NOTIFY_MESSAGE_OUT: string}
	 */
	private static function buildConnectNotifyMessages(
		Sharing $sharingModel,
		BaseObject $object,
		string $description = '',
		bool $withDisconnectLink = false,
	): array
	{
		$isFolder = $object instanceof Folder;
		$isBoard = !$isFolder && (int)$object->getRealObject()->getTypeFile() === TypeFile::FLIPCHART;

		$focusParams = [
			'objectId' => $sharingModel->getLinkObjectId(),
			'type' => $isFolder ? 'folder' : 'file',
		];

		$linkToView = $isBoard
			? self::getBoardLink($sharingModel->getLinkObject())
			: self::generateLinkToViewDocument($sharingModel, $object)
		;
		if (!$linkToView)
		{
			// "show" is handled only for files, for a folder it would turn into a click on the folder link
			$linkToView = UrlManager::getUrlFocusController(
				'showObjectInGrid',
				$isFolder ? $focusParams : $focusParams + ['cmd' => 'show'],
			);
		}

		$disconnectLink = '';
		if ($withDisconnectLink)
		{
			$uriToDisconnect = UrlManager::getUrlFocusController('showObjectInGrid', $focusParams + ['cmd' => 'detach']);
			$disconnectLink = '<a href="' . $uriToDisconnect . '">' . Loc::getMessage('DISK_SHARING_MODEL_TEXT_DISCONNECT_LINK') . '</a>';
		}

		$messageCode = match (true) {
			$isFolder => 'DISK_SHARING_MODEL_AUTOCONNECT_NOTIFY',
			$isBoard => 'DISK_SHARING_MODEL_AUTOCONNECT_NOTIFY_BOARD',
			default => 'DISK_SHARING_MODEL_AUTOCONNECT_NOTIFY_FILE',
		};
		$name = $isBoard ? $object->getNameWithoutExtension() : $object->getName();

		$trustedHostUrl = self::getTrustedHostUrl();
		$absoluteLinkToView = ($trustedHostUrl && str_starts_with($linkToView, '/'))
			? $trustedHostUrl . $linkToView
			: $linkToView
		;

		return [
			'NOTIFY_MESSAGE' => Loc::getMessage($messageCode, [
				'#NAME#' => '<a href="' . $linkToView . '">' . $name . '</a>',
				'#DESCRIPTION#' => $description,
				'#DISCONNECT_LINK#' => $disconnectLink,
			]),
			'NOTIFY_MESSAGE_OUT' => strip_tags(Loc::getMessage($messageCode, [
				'#NAME#' => $name,
				'#DESCRIPTION#' => $description,
				'#DISCONNECT_LINK#' => '',
			])) . "\n" . $absoluteLinkToView,
		];
	}

	/**
	 * Unlike UrlManager::getHostUrl() never falls back to the Host header of the request.
	 */
	private static function getTrustedHostUrl(): ?string
	{
		$host = (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME)
			? SITE_SERVER_NAME
			: Option::get('main', 'server_name')
		;
		if (!$host)
		{
			return null;
		}

		$protocol = \CMain::isHTTPS() ? 'https' : 'http';

		$port = Context::getCurrent()->getServer()->getServerPort();
		if ($port <> 80 && $port <> 443 && $port > 0 && mb_strpos($host, ':') === false)
		{
			$host .= ':' . $port;
		}
		elseif ($protocol === 'http' && $port == 80)
		{
			$host = str_replace(':80', '', $host);
		}
		elseif ($protocol === 'https' && $port == 443)
		{
			$host = str_replace(':443', '', $host);
		}

		return $protocol . '://' . $host;
	}

	private static function getBoardLink(File $file): string
	{
		return Driver::getInstance()->getUrlManager()->getUrlForViewBoard($file,false, 'disk_page');
	}
}
