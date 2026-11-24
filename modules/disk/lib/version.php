<?php


namespace Bitrix\Disk;


use Bitrix\Disk\Internal\Service\Quota\StorageUsageCounter;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\HtmlEditor\AiLockService;
use Bitrix\Disk\Internal\Service\HtmlViewerPolicy;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Internals\VersionTable;
use Bitrix\Disk\View\VersionViewManager;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Application;
use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

final class Version extends Internals\Model
{
	public const ERROR_COULD_NOT_CREATE_NEW_FILE = 'DISK_VERSION_22002';
	public const ERROR_COULD_NOT_DELETE_ONLY_VERSION = 'DISK_VERSION_22003';

	/** @var int */
	protected $objectId;
	/** @var BaseObject */
	protected $object;
	/** @var int */
	protected $fileId;
	/** @var int */
	protected $size;
	/** @var array */
	protected $file;
	/** @var string */
	protected $name;
	/** @var string */
	protected $extension;
	/** @var string */
	protected $miscData;
	/** @var array */
	protected $unserializeData;
	/** @var DateTime */
	protected $objectCreateTime;
	/** @var int */
	protected $objectCreatedBy;
	/** @var DateTime */
	protected $objectUpdateTime;
	/** @var int  */
	protected $objectUpdatedBy;
	/** @var int  */
	protected $globalContentVersion;


	/** @var DateTime */
	protected $createTime;
	/** @var int */
	protected $createdBy;
	/** @var  User */
	protected $createUser;
	/** @var int */
	protected $viewId;
	/** @var View\Base */
	protected $view;

	/**
	 * Gets the fully qualified name of table class which belongs to current model.
	 * @throws \Bitrix\Main\NotImplementedException
	 * @return string
	 */
	public static function getTableClassName()
	{
		return VersionTable::className();
	}

	public static function add(array $data, ErrorCollection $errorCollection)
	{
		// The object is handed over by the caller holding it: the counter of the used size below
		// resolves the storage over it, and the model on its own would load the very file the
		// caller is holding.
		$object = $data['OBJECT'] ?? null;
		unset($data['OBJECT']);

		$model = parent::add($data, $errorCollection);

		if($model)
		{
			if($object instanceof File)
			{
				$model->setAttributes(array('OBJECT' => $object));
			}

			$model->applyUsageDelta((int)$model->getSize());

			$event = new Event(Driver::INTERNAL_MODULE_ID, "onAfterAddVersion", array($model));
			$event->send();
		}

		return $model;
	}

	/**
	 * @return DateTime
	 */
	public function getCreateTime()
	{
		return $this->createTime;
	}

	/**
	 * @return int
	 */
	public function getCreatedBy()
	{
		return $this->createdBy;
	}

	/**
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
	 * @return int
	 */
	public function getFileId()
	{
		return $this->fileId;
	}

	/**
	 * @return array|null
	 */
	public function getFile()
	{
		if(!$this->fileId)
		{
			return null;
		}

		if(isset($this->file) && $this->fileId == $this->file['ID'])
		{
			return $this->file;
		}

		$this->file = \CFile::GetFileArray($this->fileId);

		if(!$this->file)
		{
			return array();
		}

		return $this->file;
	}

	public function createNewFile(Folder $targetFolder, int $createdBy, bool $generateUniqueName = false): ?File
	{
		$this->errorCollection->clear();

		$forkFileId = \CFile::CloneFile($this->getFileId());
		if (!$forkFileId)
		{
			$this->errorCollection[] = new Error('Could not copy file.', self::ERROR_COULD_NOT_CREATE_NEW_FILE);

			return null;
		}

		// The new file is a file of its own, wherever the revision it was made of came from, so the
		// write node judges its content as it judges any other: a revision stored before the policy
		// may carry a format the policy names now.
		$newFile = $targetFolder->addFile([
			'NAME' => $this->getName(),
			'FILE_ID' => $forkFileId,
			'SIZE' => $this->getSize(),
			'CREATED_BY' => $createdBy,
		], [], $generateUniqueName);

		if (!$newFile)
		{
			\CFile::delete($forkFileId);
			$this->errorCollection->add($targetFolder->getErrors());

			return null;
		}

		return $newFile;
	}

	/**
	 * Returns id of view.
	 * @return int|null
	 */
	public function getViewId()
	{
		return $this->viewId;
	}

	/**
	 * Set viewId, save in the database.
	 *
	 * @param int $fileId
	 * @return bool
	 */
	public function changeViewId($fileId)
	{
		return $this->update(array('VIEW_ID' => $fileId));
	}

	/**
	 * Delete converted view file.
	 *
	 * @return bool
	 */
	public function deleteView()
	{
		if($this->viewId > 0)
		{
			\CFile::Delete($this->viewId);
			return $this->update(array('VIEW_ID' => null));
		}

		return false;
	}

	/**
	 * @return int
	 */
	public function getGlobalContentVersion()
	{
		return $this->globalContentVersion;
	}

	/**
	 * @return DateTime
	 */
	public function getObjectCreateTime()
	{
		return $this->objectCreateTime;
	}

	/**
	 * @return int
	 */
	public function getObjectCreatedBy()
	{
		return $this->objectCreatedBy;
	}

	/**
	 * @return DateTime
	 */
	public function getObjectUpdateTime()
	{
		return $this->objectUpdateTime;
	}

	/**
	 * @return int
	 */
	public function getObjectUpdatedBy()
	{
		return $this->objectUpdatedBy;
	}

	/**
	 * @return int
	 */
	public function getSize()
	{
		return $this->size;
	}

	/**
	 * @return string
	 */
	public function getMiscData()
	{
		return $this->miscData;
	}

	/**
	 * @return array
	 */
	public function getUnserializeMiscData()
	{
		if(isset($this->unserializeData))
		{
			return $this->unserializeData;
		}

		if(is_string($this->miscData))
		{
			$this->unserializeData = @unserialize($this->miscData, ['allowed_classes' => false]);
			if($this->unserializeData === false)
			{
				return array();
			}
		}

		return $this->unserializeData;
	}

	public function getMiscDataByKey($key)
	{
		if(!isset($this->unserializeData))
		{
			$this->getUnserializeMiscData();
		}

		return isset($this->unserializeData[$key])? $this->unserializeData[$key] : null;
	}

	/**
	 * @return string
	 */
	public function getName()
	{
		return $this->name;
	}

	public function getExtension()
	{
		if($this->extension === null)
		{
			$this->extension = getFileExtension($this->getName());
		}
		return $this->extension;
	}

	/**
	 * @return int
	 */
	public function getObjectId()
	{
		return $this->objectId;
	}

	/**
	 * @return File|null
	 */
	public function getObject()
	{
		if(!$this->objectId)
		{
			return null;
		}

		if(isset($this->object) && $this->objectId == $this->object->getId())
		{
			return $this->object;
		}
		$this->object = File::loadById($this->objectId);

		return $this->object;
	}

	/**
	 * Join data from another version.
	 * @param $data
	 * @return bool
	 * @internal
	 */
	public function joinData(array $data)
	{
		$previousSize = (int)$this->size;

		$success = $this->update(array_intersect_key($data, array(
			'CREATE_TIME' => true,

			'FILE_ID' => true,
			'SIZE' => true,

			'GLOBAL_CONTENT_VERSION' => true,

			'OBJECT_CREATED_BY' => true,
			'OBJECT_UPDATED_BY' => true,

			'OBJECT_CREATE_TIME'=> true,
			'OBJECT_UPDATE_TIME'=> true,

			'VIEW_ID' => true,
		)));

		if(!$success)
		{
			return false;
		}

		// No row is created by a join: the last one is rewritten, so the used size moves by the
		// difference of the sizes rather than by the whole of the new one.
		$this->applyUsageDelta((int)$this->size - $previousSize);

		return true;
	}

	/**
	 * Returns true if the version is head for the file.
	 *
	 * @return bool
	 */
	public function isHead()
	{
		$file = $this->getObject();
		if(!$file)
		{
			return false;
		}

		return $this->getFileId() == $file->getFileId();
	}

	/**
	 * @return Version
	 */
	private function getPrevious()
	{
		$versions = static::getModelList(array(
			'filter' => array(
				'OBJECT_ID' => $this->objectId,
				'<ID' => $this->id,
			),
			'order' => array(
				'ID' => 'DESC',
			),
			'limit' => 1
		));
		return array_shift($versions)?: null;
	}

	/**
	 * Deletes version.
	 *
	 * @param int $deletedBy Id of user.
	 * @return bool
	 */
	public function delete($deletedBy)
	{
		$this->errorCollection->clear();
		$file = $this->getObject();
		if (
			!$file
			|| !HtmlViewerPolicy::isViewableFile($file)
			|| $file->getCurrentState() === $file::STATE_DELETE_PROCESS
		)
		{
			return $this->deleteInsideTransaction($deletedBy);
		}

		return (bool)AuditTransaction::run(function (AuditTransaction $transaction) use ($deletedBy): bool {
			$objectId = (int)$this->objectId;
			$table = ObjectTable::getTableName();
			Application::getConnection()->queryExecute("SELECT ID FROM {$table} WHERE ID = {$objectId} FOR UPDATE");
			try
			{
				$aiLock = (new AiLockService())->getSnapshot($objectId);
			}
			catch (\Throwable $exception)
			{
				Application::getInstance()->getExceptionHandler()->writeToLog($exception);
				$this->errorCollection[] = new Error(AiLockService::ERROR_UNAVAILABLE, AiLockService::ERROR_UNAVAILABLE);

				return false;
			}
			$this->object = File::loadById($objectId);

			return $this->deleteInsideTransaction($deletedBy, $transaction, $aiLock !== null);
		});
	}

	private function deleteInsideTransaction(
		int $deletedBy,
		?AuditTransaction $transaction = null,
		bool $aiLocked = false,
	): bool
	{
		$size = (int)$this->size;

		// The file is switched onto the previous version before anything of this one is removed:
		// a refused switch must leave the version and its content in place, not a file pointing at nothing.
		$file = $this->getObject();
		if($file && $file->getCurrentState() !== $file::STATE_DELETE_PROCESS && $this->isHead())
		{
			if ($aiLocked)
			{
				$this->errorCollection[] = new Error(AiLockService::ERROR_LOCKED, AiLockService::ERROR_LOCKED);

				return false;
			}
			$previous = $this->getPrevious();
			if (!$previous)
			{
				// There is no content to switch the file onto: the file would keep FILE_ID of the removed content.
				$this->errorCollection[] = new Error(
					Loc::getMessage('DISK_VERSION_MODEL_ERROR_COULD_NOT_DELETE_ONLY_VERSION'),
					self::ERROR_COULD_NOT_DELETE_ONLY_VERSION,
				);

				return false;
			}

			if(!$file->switchToContentOfVersion($previous, $deletedBy))
			{
				$this->errorCollection->add($file->getErrors());

				return false;
			}

			$previous->update(array(
				'GLOBAL_CONTENT_VERSION' => $file->getGlobalContentVersion()
			));
		}

		$success = parent::deleteInternal();
		if(!$success)
		{
			return false;
		}

		// The row has left the table, so SUM(b_disk_version.SIZE) is already lower by its size.
		$this->applyUsageDelta(-$size);

		$fileId = $this->fileId;
		$viewId = $this->viewId;
		$deleteContent = static function () use ($fileId, $viewId): void {
			\CFile::delete($fileId);
			if ($viewId)
			{
				\CFile::delete($viewId);
			}
		};
		if ($transaction !== null)
		{
			$transaction->addAfterConfirmedCommit($deleteContent);
		}
		else
		{
			$deleteContent();
		}

		return true;
	}

	/**
	 * Moves the used size counter by the movement of this version row.
	 *
	 * The delta belongs to the owner of the personal storage the object of the version lies in, not
	 * to the author of the operation: an attachment to the task of another employee spends the disk
	 * of the one whose storage is written.
	 *
	 * @param int $deltaBytes Movement of the source of truth, bytes.
	 * @return void
	 */
	private function applyUsageDelta(int $deltaBytes): void
	{
		if ($deltaBytes === 0)
		{
			return;
		}

		$ownerId = $this->findPersonalStorageOwnerId();
		if ($ownerId === null)
		{
			return;
		}

		ServiceLocator::getInstance()->get(StorageUsageCounter::class)->applyDelta($ownerId, $deltaBytes);
	}

	/**
	 * Owner of the personal storage this version belongs to, or null for everything the quota says
	 * nothing about: a group storage, the common one, the storage of an application.
	 *
	 * @return int|null
	 */
	private function findPersonalStorageOwnerId(): ?int
	{
		$storage = $this->getObject()?->getStorage();
		if (!($storage?->getProxyType() instanceof ProxyType\User))
		{
			return null;
		}

		$ownerId = (int)$storage->getEntityId();

		return $ownerId > 0 ? $ownerId : null;
	}

	/**
	 * @return array
	 */
	public static function getMapAttributes()
	{
		return array(
			'ID' => 'id',
			'OBJECT_ID' => 'objectId',
			'OBJECT' => 'object',
			'FILE_ID' => 'fileId',
			'SIZE' => 'size',
			'NAME' => 'name',
			'MISC_DATA' => 'miscData',

			'OBJECT_CREATE_TIME' => 'objectCreateTime',
			'OBJECT_CREATED_BY' => 'objectCreatedBy',
			'OBJECT_UPDATE_TIME' => 'objectUpdateTime',
			'OBJECT_UPDATED_BY' => 'objectUpdatedBy',
			'GLOBAL_CONTENT_VERSION' => 'globalContentVersion',

			'CREATE_TIME' => 'createTime',
			'CREATED_BY' => 'createdBy',
			'CREATE_USER' => 'createUser',
			'VIEW_ID' => 'viewId',
		);
	}

	/**
	 * @return array
	 */
	public static function getMapReferenceAttributes()
	{
		return array(
			'CREATE_USER' => array(
				'class' => User::className(),
				'select' => User::getFieldsForSelect(),
			),
			'OBJECT' => File::className(),
		);
	}

	/**
	 * Return instance of View for current version.
	 *
	 * @return View\Base
	 */
	public function getView()
	{
		if(!$this->view)
		{
			$isTransformationEnabledInStorage = true;
			$storage = $this->getObject()->getStorage();
			if($storage)
			{
				$isTransformationEnabledInStorage = $storage->isEnabledTransformation();
			}
			if(TypeFile::isDocument($this->name))
			{
				$this->view = new View\Document($this->getName(), $this->getFileId(), $this->getViewId(), $isTransformationEnabledInStorage);
			}
			elseif(TypeFile::isVideo($this->name))
			{
				$this->view = new View\Video($this->getName(), $this->getFileId(), $this->getViewId(), $isTransformationEnabledInStorage);
			}
			else
			{
				$this->view = new View\Base($this->getName(), $this->getFileId(), $this->getViewId(), $isTransformationEnabledInStorage);
			}
		}

		return $this->view;
	}
}
