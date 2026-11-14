<?php

namespace Bitrix\Disk\Uf;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Internals\Error\IErrorable;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\User;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\SystemException;

final class UserFieldManager implements IErrorable
{
	/** @var  ErrorCollection */
	protected $errorCollection;

	protected $additionalConnectorList = null;
	/** @var AttachedObject[]  */
	protected $loadedAttachedObjects = array();

	/**
	 * Constructor of UserFiedManager.
	 */
	public function __construct()
	{
		$this->errorCollection = new ErrorCollection();
	}

	/**
	 * Gets values of user fields for file or folder.
	 * @param BaseObject $object Target object.
	 * @return array
	 */
	public function getFieldsForObject(BaseObject $object)
	{
		/** @var \CAllUserTypeManager */
		global $USER_FIELD_MANAGER;
		return $USER_FIELD_MANAGER->getUserFields($this->getUfEntityName($object), $object->getId(), LANGUAGE_ID);
	}

	/**
	 * Gets data which describes specific connector by entity type.
	 * @param string $entityType Entity type (ex. sonet_comment).
	 * @return array|null Array with two elements: connector class and module.
	 */
	public function getConnectorDataByEntityType($entityType)
	{
		$defaultConnectors = $this->getDefaultConnectors();
		$entityType = mb_strtolower($entityType);

		if(isset($defaultConnectors[$entityType]))
		{
			return $defaultConnectors[$entityType];
		}

		$data = $this->getAdditionalConnector($entityType);

		return $data ?? [StubConnector::className(), Driver::INTERNAL_MODULE_ID];
	}

	/**
	 * Returns full list of available connectors for attached objects.
	 *
	 * @return array
	 */
	public function getConnectors()
	{
		return array_merge($this->getDefaultConnectors(), $this->getAdditionalConnectors());
	}

	private function getDefaultConnectors()
	{
		return array(
			'blog_comment' => array(BlogPostCommentConnector::className(), 'blog'),
			'blog_post' => array(BlogPostConnector::className(), 'blog'),
			'calendar_event' => array(CalendarEventConnector::className(), 'calendar'),
			'forum_message' => array(ForumMessageConnector::className(), 'forum'),
			'sonet_log' => array(SonetLogConnector::className(), 'socialnetwork'),
			'sonet_comment' => array(SonetCommentConnector::className(), 'socialnetwork'),
			'iblock_element' => array(IblockElementConnector::className(), 'iblock'),
			'iblock_workflow' => array(IblockWorkflowConnector::className(), 'iblock'),
			'im_call' => [MessengerCallConnector::class, 'im'],
		);
	}

	/**
	 * Gets input name for hidden input in disk.uf.file by entity type.
	 * This name will use in process saving user type (disk_file).
	 * @param string $entityType Entity type (ex. sonet_comment).
	 * @return string
	 */
	public function getInputNameForAllowEditByEntityType($entityType)
	{
		return $entityType . '_DISK_ATTACHED_OBJECT_ALLOW_EDIT';
	}

	public function getInputNameForTemplateView($entityType)
	{
		return $entityType . '_DISK_ATTACHED_OBJECT_TEMPLATE_VIEW';
	}

	/**
	 * Checks attitude attached object to entity. It is important in right checking in components disk.uf.file, disk.uf.version.
	 * @param AttachedObject $attachedObject Attached object.
	 * @param string $entityType Entity type (ex. sonet_comment).
	 * @param int $entityId Id of entity.
	 * @return bool
	 */
	public function belongsToEntity(AttachedObject $attachedObject, $entityType, $entityId)
	{
		list($connectorClass, $moduleId) = $this->getConnectorDataByEntityType($entityType);

		return
			$attachedObject->getEntityId()   == $entityId &&
			$attachedObject->getModuleId()   === $moduleId &&
			$attachedObject->getEntityType() === $connectorClass
		;
	}

	/**
	 * Resolves the user whose rights are checked for attached objects of another entity.
	 * Unlike the activity user of the field types, there is no fallback to the system user.
	 *
	 * @internal
	 * @param int|string|false|null $userId Id of user, false or null for the current authorized user.
	 * @return int Id of user or 0 if there is no authorized user.
	 */
	public static function resolveCheckingUserId(int|string|false|null $userId): int
	{
		if ($userId === false || $userId === null)
		{
			return (int)User::resolveUserId(CurrentUser::get());
		}

		return (int)$userId;
	}

	/**
	 * Checks whether the user field of the entity type may hold attached objects of other entities:
	 * the connector of the entity type supports foreign connectors.
	 *
	 * @internal
	 * @param string $entityType Entity type (ex. sonet_comment).
	 * @return bool
	 */
	public function supportsForeignAttachedObjects(string $entityType): bool
	{
		[$connectorClass] = $this->getConnectorDataByEntityType($entityType);

		return is_string($connectorClass) && is_a($connectorClass, ISupportForeignConnector::class, true);
	}

	/**
	 * Resolves the user whose rights are checked when a component shows attached objects of other entities:
	 * the recipient for the mail template, otherwise the current user.
	 *
	 * @internal
	 * @param string $templateName Template name of the component.
	 * @param array $componentParams Parameters of the component (arParams).
	 * @param int $currentUserId Id of the current user.
	 * @return int
	 */
	public static function resolveViewerId(string $templateName, array $componentParams, int $currentUserId): int
	{
		if ($templateName !== 'mail')
		{
			return $currentUserId;
		}

		$params = is_array($componentParams['PARAMS'] ?? null)
			? array_change_key_case($componentParams['PARAMS'], CASE_UPPER)
			: []
		;
		foreach ([$params['RECIPIENT_ID'] ?? 0, $componentParams['RECIPIENT_ID'] ?? 0] as $recipientId)
		{
			if (is_scalar($recipientId) && (int)$recipientId > 0)
			{
				return (int)$recipientId;
			}
		}

		return $currentUserId;
	}

	/**
	 * Checks whether the attached object may be used in the user field of the entity.
	 * The own attached object of the entity is allowed, the attached object of another entity
	 * requires read access of the user.
	 * The access is asked from a new connector: the attached object and its connector may be kept
	 * for the process, and connectors remember the answer of canRead regardless of the user.
	 *
	 * @internal
	 * @param AttachedObject $attachedObject Attached object.
	 * @param string $entityType Entity type (ex. sonet_comment).
	 * @param int $entityId Id of entity.
	 * @param int $userId Id of user.
	 * @return bool
	 */
	public function canUseAttachedObject(
		AttachedObject $attachedObject,
		string $entityType,
		int $entityId,
		int $userId,
	): bool
	{
		if ($this->belongsToEntity($attachedObject, $entityType, $entityId))
		{
			return true;
		}

		$connector = Connector::buildFromAttachedObject($attachedObject);
		if (!$userId && !$connector->isAnonymousAllowed())
		{
			return false;
		}

		return (bool)$connector->canRead($userId);
	}

	/**
	 * Deletes attached objects which belong to the entity. Attached objects of other entities are kept.
	 *
	 * @internal
	 * @param array $attachedObjectIds List of attached objects id.
	 * @param string $entityType Entity type (ex. sonet_comment).
	 * @param int $entityId Id of entity.
	 * @return void
	 */
	public function detachOwnAttachedObjects(array $attachedObjectIds, string $entityType, int $entityId): void
	{
		$ids = [];
		foreach ($attachedObjectIds as $attachedObjectId)
		{
			if (!is_scalar($attachedObjectId))
			{
				continue;
			}

			$attachedObjectId = (int)$attachedObjectId;
			if ($attachedObjectId > 0)
			{
				$ids[$attachedObjectId] = $attachedObjectId;
			}
		}

		if (empty($ids))
		{
			return;
		}

		$attachedObjects = AttachedObject::getModelList([
			'filter' => ['ID' => array_values($ids)],
		]);
		foreach ($attachedObjects as $attachedObject)
		{
			if ($this->belongsToEntity($attachedObject, $entityType, $entityId))
			{
				$attachedObject->delete();
			}
		}
	}

	/**
	 * Checks whether the saved value of the user field contains the attached object.
	 *
	 * @internal
	 * @param mixed $savedValue Saved value of the user field: scalar or array.
	 * @param int $attachedObjectId Id of attached object.
	 * @return bool
	 */
	public static function hasAttachedObjectIdInValue(mixed $savedValue, int $attachedObjectId): bool
	{
		if ($attachedObjectId <= 0)
		{
			return false;
		}

		$values = is_array($savedValue) ? $savedValue : [$savedValue];
		foreach ($values as $value)
		{
			if (is_scalar($value) && (int)$value === $attachedObjectId)
			{
				return true;
			}
		}

		return false;
	}

	private function getAdditionalConnectors()
	{
		if($this->additionalConnectorList === null)
		{
			$this->buildAdditionalConnectorList();
		}

		return $this->additionalConnectorList;
	}

	private function getAdditionalConnector($entityType)
	{
		$additionalConnectorList = $this->getAdditionalConnectors();

		return $additionalConnectorList[$entityType] ?? null;
	}

	private function buildAdditionalConnectorList()
	{
		$this->additionalConnectorList = array();

		$event = new Event(Driver::INTERNAL_MODULE_ID, 'onBuildAdditionalConnectorList');
		$event->send();

		foreach($event->getResults() as $evenResult)
		{
			if($evenResult->getType() != EventResult::SUCCESS)
			{
				continue;
			}

			$result = $evenResult->getParameters();
			if(!is_array($result))
			{
				throw new SystemException('Wrong event result by building AdditionalConnectorList. Must be array.');
			}

			foreach($result as $connector)
			{
				if(empty($connector['ENTITY_TYPE']))
				{
					throw new SystemException('Wrong event result by building AdditionalConnectorList. Could not find ENTITY_TYPE.');
				}

				if(empty($connector['MODULE_ID']))
				{
					throw new SystemException('Wrong event result by building AdditionalConnectorList. Could not find MODULE_ID.');
				}

				if(empty($connector['CLASS']))
				{
					throw new SystemException('Wrong event result by building AdditionalConnectorList. Could not find CLASS.');
				}

				if(is_string($connector['CLASS']) && class_exists($connector['CLASS']))
				{
					$this->additionalConnectorList[mb_strtolower($connector['ENTITY_TYPE'])] = array(
						$connector['CLASS'],
						$connector['MODULE_ID']
					);
				}
				else
				{
					throw new SystemException('Wrong event result by building AdditionalConnectorList. Could not find class by CLASS.');
				}
			}
		}
	}

	/**
	 * Shows component disk.uf.file (edit mode).
	 *
	 * @param array &$params Component parameters.
	 * @param array &$result Component results.
	 * @param null $component Component.
	 * @return void
	 */
	public function showEdit(&$params, &$result, $component = null)
	{
		if (!Configuration::isSuccessfullyConverted())
		{
			return;
		}

		global $APPLICATION;

		$map = [
			'DISABLE_LOCAL_EDIT' => false,
			'DISABLE_CREATING_FILE_BY_CLOUD' => false,
			'USE_TOGGLE_VIEW' => false,
		];

		foreach (array_keys($map) as $key)
		{
			$map[$key] = isset($params[$key]) && ($params[$key] === "Y" || $params[$key] === true);
		}

		$upperParams = array_change_key_case($params, CASE_UPPER);
		$APPLICATION->includeComponent(
			'bitrix:disk.uf.file',
			($upperParams['MOBILE'] ?? null) === 'Y' ? 'mobile' : '.default',
			[
				'EDIT' => 'Y',
				'PARAMS' => $params,
				'RESULT' => $result,
				'DISABLE_LOCAL_EDIT' => $map['DISABLE_LOCAL_EDIT'],
				'DISABLE_CREATING_FILE_BY_CLOUD' => $map['DISABLE_CREATING_FILE_BY_CLOUD'],
				'TEMPLATE_VIEW' => FileUserType::getTemplateType($params)
			],
			$component,
			['HIDE_ICONS' => 'Y']
		);
	}

	/**
	 * Shows component disk.uf.file (show inline mode).
	 *
	 * @param array &$params Component parameters.
	 * @param array &$result Component results.
	 * @param null $component Component.
	 * @return void
	 */
	public function showInlineView(&$params, &$result, $component = null)
	{
		global $APPLICATION;
		$upperParams = array_change_key_case($params, CASE_UPPER);

		$APPLICATION->includeComponent(
			'bitrix:disk.uf.file',
			($upperParams['MOBILE'] ?? null) === 'Y' ? 'mobile' : '.default',
			[
				'PARAMS' => $params,
				'RESULT' => $result,
			],
			$component,
			['HIDE_ICONS' => 'Y']
		);
	}

	/**
	 * Shows component disk.uf.file (show mode).
	 *
	 * @param array &$params Component parameters.
	 * @param array &$result Component results.
	 * @param null $component Component.
	 * @return void
	 */
	public function showView(&$params, &$result, $component = null)
	{
		global $APPLICATION;

		$APPLICATION->includeComponent(
			'bitrix:disk.uf.file',
			$this->getShowTemplate($params, ['mobile', 'mail', 'checklist']),
			[
				'PARAMS' => $params,
				'RESULT' => $result,
				'DISABLE_LOCAL_EDIT' => ($params['DISABLE_LOCAL_EDIT'] ?? false),
				'USE_TOGGLE_VIEW' => ($params['USE_TOGGLE_VIEW'] ?? false),
			],
			$component,
			['HIDE_ICONS' => 'Y']
		);
	}

	/**
	 * Shows component disk.uf.version.
	 *
	 * @param array &$params Component parameters.
	 * @param array &$result Component results.
	 * @param null $component Component.
	 * @return void
	 */
	public function showViewVersion(&$params, &$result, $component = null)
	{
		global $APPLICATION;

		$APPLICATION->includeComponent(
			'bitrix:disk.uf.version',
			$this->getShowTemplate($params, ['mobile', 'mail']),
			[
				'PARAMS' => $params,
				'RESULT' => $result,
				'DISABLE_LOCAL_EDIT' => (isset($params['DISABLE_LOCAL_EDIT'])? $params['DISABLE_LOCAL_EDIT'] : false),
			],
			$component,
			['HIDE_ICONS' => 'Y']
		);
	}


	/**
	 * @param array $params
	 * @param array $possibleTemplates
	 * @return string
	 */
	private function getShowTemplate($params, $possibleTemplates)
	{
		$upperParams = array_change_key_case($params, CASE_UPPER);

		$templateType = '';
		if ($params['arUserField']['USER_TYPE_ID'] === FileUserType::USER_TYPE_ID)
		{
			$templateType = FileUserType::getTemplateType($upperParams);
		}

		if (($upperParams['MOBILE'] ?? null) === 'Y')
		{
			$template = 'mobile'.($templateType === 'grid' ? '_grid' : '');
		}
		else
		{
			$template = $upperParams['TEMPLATE'] ?? null;
			$template = (
				in_array($template, $possibleTemplates, true)
					? $template
					: ($templateType === 'grid' ? 'grid' : '')
			);
		}

		return $template;
	}

	/**
	 * @param BaseObject $object
	 * @return string
	 */
	public function getUfEntityName(BaseObject $object)
	{
		if($object instanceof File)
		{
			return 'DISK_FILE_' . $object->getStorageId();
		}
		return 'DISK_FOLDER_' . $object->getStorageId();
	}

	/**
	 * Preload AttachedObjects.
	 * @param array $ids List of attached objects id.
	 * @return void
	 */
	public function loadBatchAttachedObject(array $ids)
	{
		foreach($ids as $i => &$id)
		{
			if(isset($this->loadedAttachedObjects[$id]))
			{
				unset($ids[$i]);
			}
			if(!is_numeric($id))
			{
				unset($ids[$i]);
			}
			$id = (int)$id;
		}
		unset($id);

		if(empty($ids))
		{
			return;
		}

		/** @var \Bitrix\Disk\AttachedObject $attachedObject */
		$modelList = AttachedObject::getModelList([
			'filter' => ['ID' => $ids],
			'with' => ['OBJECT'],
			'extra' => [
				'FILE_CONTENT_TYPE' => 'OBJECT.FILE_CONTENT.CONTENT_TYPE',
				'FILE_WIDTH' => 'OBJECT.FILE_CONTENT.WIDTH',
				'FILE_HEIGHT' => 'OBJECT.FILE_CONTENT.HEIGHT',
				'FILE_SIZE' => 'OBJECT.FILE_CONTENT.FILE_SIZE',
			],
		]);
		foreach($modelList as $attachedObject)
		{
			$this->loadedAttachedObjects[$attachedObject->getId()] = $attachedObject;
		}
		unset($attachedObject);
	}

	/**
	 * Returns list of attached object which are attached to entity.
	 *
	 * @param string $entity Entity name.
	 * @param string|int $entityId Entity id.
	 * @param string $fieldName Field name.
	 *
	 * @return AttachedObject[]
	 */
	public function getAttachedObjectByEntity($entity, $entityId, $fieldName)
	{
		/** @var \CUserTypeManager */
		global $USER_FIELD_MANAGER;

		$values = $USER_FIELD_MANAGER->getUserFieldValue($entity, $fieldName, $entityId);
		if (!$values)
		{
			return array();
		}

		$this->loadBatchAttachedObject($values);

		return array_intersect_key($this->loadedAttachedObjects, array_combine($values, $values));
	}

	/**
	 * Preload AttachedObjects in blog posts.
	 * @param array $blogPostIds List of blog post id.
	 * @return void
	 */
	public function loadBatchAttachedObjectInBlogPost(array $blogPostIds)
	{
		if(empty($blogPostIds))
		{
			return;
		}

		list($connectorClass, $moduleId) = $this->getConnectorDataByEntityType('BLOG_POST');

		$with = ['OBJECT'];
		if(Configuration::isEnabledObjectLock())
		{
			$with[] = 'OBJECT.LOCK';
		}

		$modelList = AttachedObject::getModelList(
			[
				'with' => $with,
				'filter' => [
					'=ENTITY_TYPE' => $connectorClass,
					'ENTITY_ID' => $blogPostIds,
					'=MODULE_ID' => $moduleId,
				],
				'extra' => [
					'FILE_CONTENT_TYPE' => 'OBJECT.FILE_CONTENT.CONTENT_TYPE',
					'FILE_WIDTH' => 'OBJECT.FILE_CONTENT.WIDTH',
					'FILE_HEIGHT' => 'OBJECT.FILE_CONTENT.HEIGHT',
					'FILE_SIZE' => 'OBJECT.FILE_CONTENT.FILE_SIZE',
				],
			]
		);
		foreach($modelList as $attachedObject)
		{
			/** @var \Bitrix\Disk\AttachedObject $attachedObject */
			$this->loadedAttachedObjects[$attachedObject->getId()] = $attachedObject;
		}
		unset($attachedObject);
	}

	/**
	 * Checks by id of attached object status of loading data in memory (optimization).
	 * @param int $id Id of attached object.
	 * @return bool
	 */
	public function isLoadedAttachedObject($id)
	{
		return !empty($this->loadedAttachedObjects[$id]);
	}

	/**
	 * Gets attached object by id (optimization).
	 * @param int $id Id of attached object.
	 * @return AttachedObject|null
	 */
	public function getAttachedObjectById($id)
	{
		if(!isset($this->loadedAttachedObjects[$id]))
		{
			$this->loadedAttachedObjects[$id] = AttachedObject::loadById($id, ['OBJECT']);
		}
		return $this->loadedAttachedObjects[$id];
	}

	/**
	 * Getting array of errors.
	 * @return Error[]
	 */
	public function getErrors()
	{
		return $this->errorCollection->toArray();
	}

	/**
	 * Getting array of errors with the necessary code.
	 * @param string $code Code of error.
	 * @return Error[]
	 */
	public function getErrorsByCode($code)
	{
		return $this->errorCollection->getErrorsByCode($code);
	}

	/**
	 * Getting once error with the necessary code.
	 * @param string $code Code of error.
	 * @return Error[]
	 */
	public function getErrorByCode($code)
	{
		return $this->errorCollection->getErrorByCode($code);
	}

	/**
	 * Clones uf values from entity and creates new files (copies from attach) to save in new entity.
	 * @param array $attachedIds List of attached objects id.
	 * @param int $userId Id of user.
	 * @internal
	 * @return array
	 */
	public function cloneUfValuesFromAttachedObject(array $attachedIds, $userId): ?array
	{
		$this->errorCollection->clear();

		$userId = (int)$userId;
		if ($userId <= 0)
		{
			$this->errorCollection[] = new Error('Invalid $userId');

			return null;
		}

		$userStorage = Driver::getInstance()->getStorageByUserId($userId);
		if (!$userStorage)
		{
			$this->errorCollection[] = new Error("Could not find storage for user {$userId}");
			$this->errorCollection->add(Driver::getInstance()->getErrors());

			return null;
		}

		$folder = $userStorage->getFolderForUploadedFiles();
		if (!$folder)
		{
			$this->errorCollection[] = new Error("Could not create/find folder for upload");
			$this->errorCollection->add($userStorage->getErrors());

			return null;
		}

		$newValues = [];
		foreach ($attachedIds as $id)
		{
			[$type, $realValue] = FileUserType::detectType($id);
			if (FileUserType::TYPE_ALREADY_ATTACHED != $type)
			{
				continue;
			}

			$attachedObject = AttachedObject::loadById($realValue, ['OBJECT']);
			if (!$attachedObject)
			{
				continue;
			}

			if (!$attachedObject->canRead($userId))
			{
				continue;
			}

			$file = $attachedObject->getFile();
			if (!$file)
			{
				continue;
			}

			if (!$attachedObject->isSpecificVersion())
			{
				$newFile = $file->copyTo($folder, $userId, true);
				if (!$newFile)
				{
					$this->errorCollection->add($file->getErrors());
					continue;
				}
			}
			else
			{
				$version = $attachedObject->getVersion();
				if (!$version)
				{
					continue;
				}

				$newFile = $version->createNewFile($folder, $userId, true);
				if (!$newFile)
				{
					$this->errorCollection->add($file->getErrors());
					continue;
				}
			}

			$newValues[$id] = FileUserType::NEW_FILE_PREFIX . $newFile->getId();
		}

		return $newValues;
	}
}
