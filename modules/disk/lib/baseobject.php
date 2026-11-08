<?php

namespace Bitrix\Disk;

use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkSupportService;
use Bitrix\Disk\Internal\Enum\ExternalLinkScenario;
use Bitrix\Disk\Internal\Enum\SharingMemberType;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkScenarioRegistry;
use Bitrix\Disk\Internal\Service\ExternalLink\MailAttachmentLinkContract;
use Bitrix\Disk\Internal\Service\Folder\MailAttachmentsFolderPredicate;
use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeType;
use Bitrix\Disk\Internal\Service\Sync\Journal\JournalWriter;
use Bitrix\Disk\Internal\Service\Sync\Journal\TombstoneReason;
use Bitrix\Disk\Internal\Service\UnifiedLink\UniqueCodeBackfiller;
use Bitrix\Disk\Internals\Entity\ModelSynchronizer;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internals\ObjectNameService;
use Bitrix\Disk\Internals\ObjectOptionsTable;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\SharingTable;
use Bitrix\Disk\Realtime\Events\ObjectEvent;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Ui\Avatar;
use Bitrix\Im\Model\ChatTable;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Entity\AddResult;
use Bitrix\Main\Entity\Result;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

abstract class BaseObject extends Internals\Model implements \JsonSerializable
{
	public const ERROR_NON_UNIQUE_NAME               = 'DISK_OBJ_22000';
	public const ERROR_RESTORE_UNDER_LINK_WRONG_TYPE = 'DISK_OBJ_22001';

	/** @var string */
	protected $name;
	/** @var string */
	protected $label;
	/** @var string */
	protected $code;
	/** @var string */
	protected $xmlId;
	/** @var int */
	protected $storageId;
	/** @var  Storage */
	protected $storage;
	/** @var int */
	protected $type;
	/** @var int */
	protected $realObjectId;
	/** @var BaseObject */
	protected $realObject;
	/** @var int */
	protected $parentId;
	/** @var Folder */
	protected $parent;
	/** @var Document\CloudImport\Entry */
	protected $lastCloudImport;
	/** @var string */
	protected $contentProvider;
	/** @var int */
	protected $deletedType;
	/** @var ObjectLock */
	protected $lock;
	/** @var ObjectTtl */
	protected $ttl;

	/** @var DateTime */
	protected $createTime;
	/** @var DateTime */
	protected $updateTime;
	/** @var DateTime */
	protected $syncUpdateTime;
	/** @var DateTime */
	protected $deleteTime;

	/** @var int */
	protected $createdBy;
	/** @var  User */
	protected $createUser;
	/** @var int */
	protected $updatedBy;
	/** @var  User */
	protected $updateUser;
	/** @var int */
	protected $deletedBy;
	/** @var  User */
	protected $deleteUser;
	/** @var string|null */
	protected $uniqueCode;
	protected ?array $objectOptions = null;

	/**
	 * Whether an operation over a subtree reached this object rather than started on it. The journal of
	 * changes takes one record on the root of such an operation, and the object a cascade merely reached
	 * publishes nothing: a folder of ten thousand children would otherwise write ten thousand records
	 * saying what the record of the root already says.
	 *
	 * Set by the cascade itself - {@see Folder::deleteTree()}, {@see Folder::restore()} - because the
	 * root of an operation is known to the code running it and cannot be told from the state of a row.
	 */
	protected bool $journalCascadeChild = false;

	private ?JournalWriter $journalWriter = null;

	/**
	 * Returns the fully qualified name of table class which belongs to current model.
	 * @throws \Bitrix\Main\NotImplementedException
	 * @return string
	 */
	public static function getTableClassName()
	{
		return ObjectTable::className();
	}

	/**
	 * Checks rights to change rights on current object.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canChangeRights(SecurityContext $securityContext)
	{
		return $securityContext->canChangeRights($this->id);
	}

	/**
	 * Checks rights to delete current object.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canDelete(SecurityContext $securityContext)
	{
		return $securityContext->canDelete($this->id);
	}

	/**
	 * Checks rights to mark deleted current object.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canMarkDeleted(SecurityContext $securityContext)
	{
		return $securityContext->canMarkDeleted($this->id);
	}

	/**
	 * Checks rights to move current object in new destination object.
	 * @param SecurityContext $securityContext Security context.
	 * @param BaseObject $targetObject New destination object.
	 * @return bool
	 */
	public function canMove(SecurityContext $securityContext, self $targetObject)
	{
		return $securityContext->canMove($this->id, $targetObject->getRealObjectId());
	}

	/**
	 * Checks rights to read current object.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canRead(SecurityContext $securityContext)
	{
		return $securityContext->canRead($this->id);
	}

	/**
	 * Checks rights to download current object.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canDownload(SecurityContext $securityContext): bool
	{
		return $securityContext->canDownload($this->getRealObjectId());
	}

	/**
	 * Checks rights to rename current object.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canRename(SecurityContext $securityContext)
	{
		return $securityContext->canRename($this->id);
	}

	/**
	 * Checks rights to restore current object from trash can.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canRestore(SecurityContext $securityContext)
	{
		return
			$securityContext->canRestore($this->id)
			|| (
				$securityContext->canMarkDeleted($this->id)
				&& $this->deletedBy == $securityContext->getUserId()
				&& $this->deletedBy
			);
	}

	/**
	 * Checks rights to share current object.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canShare(SecurityContext $securityContext)
	{
		return $securityContext->canShare($this->id);
	}

	/**
	 * Checks rights to update (content) current object.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canUpdate(SecurityContext $securityContext)
	{
		return $securityContext->canUpdate($this->id);
	}

	/**
	 * Checks rights to update current object by cloud import.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canUpdateByCloudImport(SecurityContext $securityContext)
	{
		return
				$this->getContentProvider()
				&& $this->getCreatedBy() == $securityContext->getUserId()
				&& $securityContext->canUpdate($this->id);
	}

	/**
	 * Checks rights to lock (content) the object.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canLock(SecurityContext $securityContext)
	{
		return $securityContext->canUpdate($this->id);
	}

	/**
	 * Checks rights to unlock (content) the object.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canUnlock(SecurityContext $securityContext)
	{
		return $securityContext->canUpdate($this->id);
	}

	/**
	 * Returns time of create object.
	 * @return DateTime
	 */
	public function getCreateTime()
	{
		return $this->createTime;
	}

	/**
	 * Returns id of user, who created object.
	 * @return int
	 */
	public function getCreatedBy()
	{
		return $this->createdBy;
	}

	/**
	 * Returns user model, who created object.
	 * @return User
	 */
	public function getCreateUser()
	{
		if($this->isLoadedAttribute('createUser') && $this->createUser && $this->createdBy == $this->createUser->getId())
		{
			return $this->createUser;
		}
		$this->createUser = User::getModelForReferenceField($this->createdBy, $this->createUser);
		$this->setAsLoadedAttribute('createUser');

		return $this->createUser;
	}

	/**
	 * Returns time of delete object.
	 * @return DateTime
	 */
	public function getDeleteTime()
	{
		return $this->deleteTime;
	}

	public function getUniqueCode(): ?string
	{
		if (empty($this->uniqueCode))
		{
			$uniqueCodeBackfiller = ServiceLocator::getInstance()->get(UniqueCodeBackfiller::class);

			return $uniqueCodeBackfiller->getBackfilledUniqueCode((int)$this->getId());
		}

		return $this->uniqueCode;
	}

	/**
	 * Returns id of user, who deleted object.
	 * @return int
	 */
	public function getDeletedBy()
	{
		return $this->deletedBy;
	}

	/**
	 * Returns deleted type (@see ObjectTable).
	 * @return int
	 */
	public function getDeletedType()
	{
		return $this->deletedType;
	}

	/**
	 * Returns user model, who deleted object.
	 * @return User
	 */
	public function getDeleteUser()
	{
		if($this->isLoadedAttribute('deleteUser') && $this->deleteUser && $this->deletedBy == $this->deleteUser->getId())
		{
			return $this->deleteUser;
		}
		$this->deleteUser = User::getModelForReferenceField($this->deletedBy, $this->deleteUser);
		$this->setAsLoadedAttribute('deleteUser');

		return $this->deleteUser;
	}

	/**
	 * Returns real object of object.
	 *
	 * For example if object is link (@see FolderLink, @see FileLink), then method returns original object.
	 * @return BaseObject|Folder|File
	 */
	public function getRealObject()
	{
		if(!$this->isLink())
		{
			return $this;
		}

		// The id of a model loaded from the database is a string, the one of a model built in code is an int.
		$isResolved = $this->isLoadedAttribute('realObject')
			&& $this->realObject
			&& (int)$this->realObjectId === (int)$this->realObject->getId()
		;
		if($isResolved)
		{
			return $this->realObject;
		}
		$this->realObject = BaseObject::loadById($this->realObjectId);
		$this->setAsLoadedAttribute('realObject');

		return $this->realObject;
	}

	/**
	 * Returns id of real object of object.
	 *
	 * For example if object is link (@see FolderLink, @see FileLink), then method returns id of original object.
	 * @return int
	 */
	public function getRealObjectId()
	{
		return $this->isLink() ? $this->realObjectId : $this->id;
	}

	/**
	 * Returns true if object is moved in trash can.
	 * @return boolean
	 */
	public function isDeleted()
	{
		return $this->deletedType != ObjectTable::DELETED_TYPE_NONE;
	}

	/**
	 * Returns name of object.
	 * Be careful: the method returns name without possible trash can suffix.
	 * @return string
	 */
	public function getName()
	{
		if($this->label === null)
		{
			$this->label = $this->getNameWithoutTrashCanSuffix();
		}

		return $this->label;
	}

	/**
	 * Returns original name of object.
	 * May contains trash can suffix.
	 * @return string
	 */
	public function getOriginalName()
	{
		return $this->name;
	}

	protected function getNameWithTrashCanSuffix()
	{
		return Ui\Text::appendTrashCanSuffix($this->name);
	}

	protected function getNameWithoutTrashCanSuffix()
	{
		return Ui\Text::cleanTrashCanSuffix($this->name);
	}

	/**
	 * Returns code of object.
	 * Code is used for working with object by symbolic name.
	 * @return string
	 */
	public function getCode()
	{
		return $this->code;
	}

	/**
	 * Returns xml id of object.
	 * @return string
	 */
	public function getXmlId()
	{
		return $this->xmlId;
	}

	/**
	 * Returns id of parent object.
	 * If object is root, then method returns null.
	 * @return int
	 */
	public function getParentId()
	{
		return $this->parentId;
	}

	/**
	 * Returns content provider of object.
	 * Content provider determines service which provided object (for ex. Dropbox).
	 * @return string
	 */
	public function getContentProvider()
	{
		return $this->contentProvider;
	}

	/**
	 * Returns id of storage.
	 * @return int
	 */
	public function getStorageId()
	{
		return $this->storageId;
	}

	/**
	 * Returns storage model.
	 * @return Storage|null
	 */
	public function getStorage()
	{
		if(!$this->storageId)
		{
			return null;
		}

		if($this->isLoadedAttribute('storage') && $this->storage && $this->storageId == $this->storage->getId())
		{
			return $this->storage;
		}
		$this->storage = Storage::loadById($this->storageId, ['ROOT_OBJECT']);
		$this->setAsLoadedAttribute('storage');

		return $this->storage;
	}

	/**
	 * Returns type (folder or file).
	 * @see ObjectTable::TYPE_FOLDER, ObjectTable::TYPE_FILE.
	 * @return int
	 */
	public function getType()
	{
		return $this->type;
	}

	/**
	 * Returns size in bytes.
	 *
	 * @param null $filter
	 *
	 * @return int
	 */
	abstract public function getSize($filter = null);

	/**
	 * Returns time of update object. If not set returns create time.
	 * @return DateTime
	 */
	public function getUpdateTime()
	{
		return $this->updateTime ?: $this->createTime;
	}

	/**
	 * Returns sync time object.
	 * @return DateTime
	 */
	public function getSyncUpdateTime()
	{
		return $this->syncUpdateTime;
	}

	/**
	 * Returns id of user, who updated object.
	 * @return int
	 */
	public function getUpdatedBy()
	{
		return $this->updatedBy;
	}

	/**
	 * Returns user model, who created object.
	 * @return User
	 */
	public function getUpdateUser()
	{
		if($this->isLoadedAttribute('updateUser') && $this->updateUser && $this->updatedBy == $this->updateUser->getId())
		{
			return $this->updateUser;
		}
		$this->updateUser = User::getModelForReferenceField($this->updatedBy, $this->updateUser);
		$this->setAsLoadedAttribute('updateUser');

		return $this->updateUser;
	}

	/**
	 * Adds external link.
	 * @param array $data Data to create new external link (@see ExternalLink).
	 * @return bool|ExternalLink
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function addExternalLink(array $data)
	{
		$this->errorCollection->clear();

		$data['OBJECT_ID'] = $this->id;

		// Only internal services assign a scenario, never the calling code.
		unset($data['SCENARIO']);

		$predicate = ServiceLocator::getInstance()->get(MailAttachmentsFolderPredicate::class);
		$isInsideMailAttachmentsTree = $predicate->isInsideMailAttachmentsTree((int)$this->id);

		if ($isInsideMailAttachmentsTree)
		{
			// Invariant: links to objects anywhere inside the mail attachments folder tree always
			// keep their settings locked. The client-supplied CAN_EDIT_SETTINGS is ignored so the
			// flag cannot be reset on link re-creation or by passing an explicit value in the request.
			$data['CAN_EDIT_SETTINGS'] = false;
		}

		$scenario = $this->resolveExternalLinkScenario($data, $isInsideMailAttachmentsTree);
		if ($scenario === ExternalLinkScenario::MailAttachments)
		{
			// The object remembers that its public link is a service one, so a link re-created from the
			// interface comes back as a service link and with the whole contract of it, not just with
			// its name: the settings of the request cannot widen what the recipient of the letter gets.
			$data = MailAttachmentLinkContract::applyTo($data);
		}

		$addResult = ExternalLink::add($data, $this->errorCollection);

		if ($addResult)
		{
			$event = new Event(Driver::INTERNAL_MODULE_ID, 'onAfterAddExternalLinkToObject', [$this, $data]);
			$event->send();
		}

		return $addResult;
	}

	/**
	 * The service nature of a link is bounded by three conditions at once: the object is marked as a
	 * service one, the link being created is a manual one and the object still lies inside the mail
	 * attachments folder tree. An automatic link and an object taken out of the tree get no scenario,
	 * so they keep asking the portal policy as any regular link does.
	 */
	private function resolveExternalLinkScenario(array $data, bool $isInsideMailAttachmentsTree): ?ExternalLinkScenario
	{
		if (!$isInsideMailAttachmentsTree || (int)($data['TYPE'] ?? 0) !== ExternalLink::TYPE_MANUAL)
		{
			return null;
		}

		return ServiceLocator::getInstance()->get(ExternalLinkScenarioRegistry::class)->getFor($this);
	}

	/**
	 * Returns external links by file.
	 * @param array $parameters Parameters.
	 * @return ExternalLink[]
	 * @deprecated
	 */
	public function getExternalLinks(array $parameters = [])
	{
		if (!isset($parameters['filter']))
		{
			$parameters['filter'] = [];
		}
		$parameters['filter']['OBJECT_ID'] = $this->id;

		if (!isset($parameters['order']))
		{
			$parameters['order'] = [
				'CREATE_TIME' => 'DESC',
			];
		}

		return ExternalLink::getModelList($parameters);
	}

	/**
	 * Tells if object is a link on another object.
	 * @return bool
	 */
	public function isLink()
	{
		return isset($this->realObjectId) && $this->realObjectId != $this->id;
	}

	/**
	 * Returns last cloud import entry of object.
	 * @see Document\CloudImport\ImportManager, @see Document\CloudImport\Entry.
	 * @return Document\CloudImport\Entry|
	 */
	public function getLastCloudImportEntry()
	{
		if($this->lastCloudImport === null)
		{
			$lastCloudImport = Document\CloudImport\Entry::getModelList([
				'filter' => [
					'OBJECT_ID' => $this->getRealObjectId(),
				],
				'order' => [
					'ID' => 'DESC',
				],
				'limit' => 1,
			]);
			if(!$lastCloudImport)
			{
				return null;
			}
			$this->lastCloudImport = array_pop($lastCloudImport);
		}

		return $this->lastCloudImport;
	}

	/**
	 * Renames object.
	 * @param string $newName New name.
	 * @param bool $generateUniqueName Generates unique name for object in directory.
	 * @return bool
	 * @internal
	 */
	public function renameInternal(string $newName, bool $generateUniqueName = false): bool
	{
		$this->errorCollection->clear();

		if (!$newName)
		{
			$this->errorCollection[] = new Error('Empty name.');

			return false;
		}

		if ($this->name === $newName)
		{
			return true;
		}

		$nameService = new ObjectNameService($newName, $this->getParentId(), $this->getType());
		$nameService->requireOpponentId();
		$nameService->excludeId($this->getId());
		if ($generateUniqueName)
		{
			$nameService->requireUniqueName();
		}

		$result = $nameService->prepareName();
		if (!$result->isSuccess())
		{
			$this->errorCollection->add($result->getErrors());

			return false;
		}

		$newName = $result->getName();
		$now = new DateTime();

		$oldName = $this->name;
		$success = $this->update([
			'NAME' => $newName,
			'SYNC_UPDATE_TIME' => $now,
			'UPDATE_TIME' => $now,
		]);
		if (!$success)
		{
			return false;
		}
		$this->label = null;

		Driver::getInstance()->getIndexManager()->changeName($this);
		Driver::getInstance()->sendChangeStatusToSubscribers($this, 'quick');
		$this->publishRenameToJournal();

		$event = new Event(Driver::INTERNAL_MODULE_ID, 'onAfterRenameObject', [$this, $oldName, $newName]);
		$event->send();

		return true;
	}

	/**
	 * A rename reported to the journal, which is kept over real objects.
	 *
	 * A link carries a name of its own and it is that name the client shows, while the object behind the
	 * link keeps the one it had. What changed for the audience of that object is a place of it and not
	 * its name, and it is published in the same shape the appearance and the disappearance of a link are.
	 *
	 * The author is the user of the request. The method is reached from a dozen callers that pass none
	 * and the row of a rename carries no author of its own either; where there is no user - an agent, the
	 * console - the record belongs to a background mechanism and says so by carrying no author (DTO-03).
	 */
	private function publishRenameToJournal(): void
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		$authorId = $currentUserId > 0 ? $currentUserId : null;

		if ($this->isLink())
		{
			$this->publishToJournal(ChangeType::Updated, $authorId, ['places']);

			return;
		}

		$this->publishToJournal(ChangeType::Renamed, $authorId, ['name']);
	}

	/**
	 * Renames object.
	 * @param string $newName New name.
	 * @return bool
	 */
	public function rename(string $newName, bool $generateUniqueName = false)
	{
		$success = $this->renameInternal($newName, $generateUniqueName);
		if ($success)
		{
			$this->changeParentUpdateTime();

			$objectEvent = $this->makeObjectEvent(
				'objectRenamed',
				[
					'object' => [
						'id' => (int)$this->getId(),
						'type' => (int)$this->getType(),
						'name' => $this->getName(),
						'parentId' => (int)$this->getParentId(),
					],
				],
			);
			$objectEvent->sendToObjectChannel();
		}

		return $success;
	}

	/**
	 * Changes field update time of parent.
	 * @param DateTime|null $datetime Datetime.
	 * @return bool
	 */
	protected function changeParentUpdateTime(?DateTime $datetime = null, $updatedBy = null)
	{
		$parent = $this->getParent();
		if (!$parent)
		{
			return false;
		}

		$data = [
			'UPDATE_TIME' => $datetime ?: new DateTime(),
		];

		if ($updatedBy)
		{
			$data['UPDATED_BY'] = $updatedBy;
		}

		return $parent->update($data);
	}

	/**
	 * Changes field update time.
	 * @param DateTime|null $datetime Datetime.
	 * @return bool
	 */
	protected function changeSelfUpdateTime(?DateTime $datetime = null)
	{
		return $this->update([
			'UPDATE_TIME' => $datetime ?: new DateTime(),
		]);
	}

	/**
	 * Changes xml id on current element.
	 * @param string $newXmlId New xml id.
	 * @return bool
	 */
	public function changeXmlId($newXmlId)
	{
		return $this->update(['XML_ID' => $newXmlId]);
	}

	/**
	 * Changes code on current element.
	 * @param string $newCode New code.
	 * @return bool
	 */
	public function changeCode($newCode)
	{
		return $this->update(['CODE' => $newCode]);
	}

	/**
	 * Copies object to target folder.
	 * @param Folder $targetFolder Target folder.
	 * @param int    $updatedBy Id of user.
	 * @param bool   $generateUniqueName Generates unique name for object in directory.
	 * @return BaseObject|null
	 */
	abstract public function copyTo(Folder $targetFolder, $updatedBy, $generateUniqueName = false);

	/**
	 * A write which carries a name is refused by the index over the pair of name and parent, so it means
	 * a name already taken in the folder. The other unique index of the table guards the unique code and
	 * keeps the generic error.
	 */
	protected function createDuplicateEntryError(array $data): Error
	{
		if (!array_key_exists('NAME', $data))
		{
			return parent::createDuplicateEntryError($data);
		}

		return new Error(
			Loc::getMessage('DISK_OBJECT_MODEL_ERROR_NON_UNIQUE_NAME'),
			self::ERROR_NON_UNIQUE_NAME
		);
	}

	/**
	 * Builds a name which is free in any folder, so the object can be parked under it while it travels
	 * between folders.
	 */
	protected function generateUniqueTmpName(): string
	{
		$uniqueTmpName = $this->name;

		$uniquePart = '_' . Random::getString(32);
		$maxAllowedLength = 255 - mb_strlen($uniquePart);

		if (mb_strlen($this->name) > $maxAllowedLength)
		{
			$uniqueTmpName = mb_substr($this->name, 0, $maxAllowedLength);
		}
		$uniqueTmpName .= $uniquePart;

		return $uniqueTmpName;
	}

	/**
	 * Moves object to another folder.
	 * Support cross-storage move (mark deleted + create new)
	 * @param Folder $folder             Destination folder.
	 * @param int    $movedBy            User id of user, which move file.
	 * @param bool   $generateUniqueName Generates unique name for object if in destination directory.
	 * @return BaseObject|null
	 */
	public function moveTo(Folder $folder, $movedBy, $generateUniqueName = false)
	{
		$this->errorCollection->clear();

		if($this->getId() == $folder->getId())
		{
			return $this;
		}

		if (!$this->getParentId() && $this instanceof Folder)
		{
			return $this;
		}

		$realStorageIdSource = $this->getStorageId();
		$realStorageIdTarget = $folder->getRealObject()->getStorageId();

		$realFolderId = $folder->getRealObject()->getId();
		if($this->getParentId() == $realFolderId)
		{
			return $this;
		}

		$ancestors = ObjectPathTable::getAncestors($folder->getId());
		if (in_array($this->getRealObjectId(), array_column($ancestors, 'PARENT_ID'), true))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_OBJECT_MODEL_INVALID_MOVEMENT_TO_CHILD'));

			return null;
		}

		$nameService = new ObjectNameService($this->name, $realFolderId, $this->getType());
		if ($generateUniqueName)
		{
			$nameService->requireUniqueName();
		}

		$result = $nameService->prepareName();
		if (!$result->isSuccess())
		{
			$this->errorCollection->add($result->getErrors());

			return null;
		}

		$possibleNewName = $result->getName();

		//simple move
		if($realStorageIdSource == $realStorageIdTarget)
		{
			$object = $this->moveInSameStorageUnderName($folder, $movedBy, $possibleNewName, $generateUniqueName);
		}
		else
		{
			// the cross-storage branch copies the object into the target folder under this name and trashes
			// the original, so the row of the source folder keeps its own name and is never renamed here
			$this->name = $possibleNewName;
			$object = $this->moveInAnotherStorage($folder, $movedBy);
		}

		if($object !== null)
		{
			$folder->changeSelfUpdateTime();

			// A move across storages keeps no identity to report a move of: it is a copy and a trashing
			// of the source, and each of the two has already published a record of its own.
			if ($object === $this)
			{
				$this->publishToJournal(ChangeType::Moved, $movedBy, ['places']);
			}

			$event = new Event(Driver::INTERNAL_MODULE_ID, "onAfterMoveObject", [$this]);
			$event->send();
		}

		return $object;
	}

	/**
	 * Moves the object and only then gives it the name prepared for the target folder.
	 * The prepared name is free in the target folder, but can be taken in the source one, and the unique index
	 * of the object table covers the pair of name and parent, so renaming before the move breaks on a collision
	 * which has nothing to do with the destination. While the object travels it wears a temporary name free
	 * in any folder.
	 * @return $this|null
	 */
	private function moveInSameStorageUnderName(
		Folder $folder,
		$movedBy,
		string $preparedName,
		bool $generateUniqueName
	)
	{
		$originalName = $this->name;
		$needToRename = $preparedName !== $originalName;

		if ($needToRename && !$this->updateName($this->generateUniqueTmpName()))
		{
			return null;
		}

		$object = $this->moveInSameStorage($folder, $movedBy);
		if ($object === null)
		{
			if ($needToRename)
			{
				$this->restoreNameAfterFailedMove($originalName);
			}

			return null;
		}

		if ($needToRename && !$this->renameAfterMove($originalName, $folder, $generateUniqueName))
		{
			return null;
		}

		return $object;
	}

	/**
	 * Picks the final name in the folder the object already belongs to.
	 */
	private function renameAfterMove(string $desiredName, Folder $folder, bool $generateUniqueName): bool
	{
		$nameService = new ObjectNameService($desiredName, $folder->getRealObject()->getId(), $this->getType());
		$nameService->excludeId($this->getId());
		if ($generateUniqueName)
		{
			$nameService->requireUniqueName();
		}

		$result = $nameService->prepareName();
		if (!$result->isSuccess())
		{
			$this->errorCollection->add($result->getErrors());

			return false;
		}

		return $this->updateName($result->getName());
	}

	private function restoreNameAfterFailedMove(string $originalName): void
	{
		// update() resets the error collection, so the errors of the failed move have to outlive the rollback
		$errors = $this->getErrors();
		$this->updateName($originalName);

		$this->errorCollection->clear();
		$this->errorCollection->add($errors);
	}

	/**
	 * Writes the name and keeps the model in sync: an update result carries no fields back, and the label
	 * caches the name it was built from.
	 */
	private function updateName(string $name): bool
	{
		if (!$this->update(['NAME' => $name]))
		{
			return false;
		}
		$this->name = $name;
		$this->label = null;

		return true;
	}

	/**
	 * @param Folder $folder
	 * @param  int   $movedBy
	 * @return $this|null
	 * @throws \Bitrix\Main\ArgumentException
	 */
	protected function moveInSameStorage(Folder $folder, $movedBy)
	{
		$driver = Driver::getInstance();
		$subscriberManager = $driver->getSubscriberManager();

		$subscribersBeforeMove = $subscriberManager->collectSubscribersSmart($this);
		$rightsCodesBeforeMove = $driver->getRightsManager()->captureCodesBeforeMove($this);

		$realFolderId = $folder->getRealObject()->getId();
		/** @var ObjectTable $tableClassName */
		$tableClassName = $this->getTableClassName();

		$moveResult = $tableClassName::move($this->id, $realFolderId);
		if(!$moveResult->isSuccess())
		{
			foreach($moveResult->getErrors() as $error)
			{
				if($error->getCode() === self::ERROR_NON_UNIQUE_NAME)
				{
					$this->errorCollection[] = new Error(
						Loc::getMessage('DISK_OBJECT_MODEL_ERROR_NON_UNIQUE_NAME'),
						self::ERROR_NON_UNIQUE_NAME
					);

					continue;
				}

				$this->errorCollection[] = new Error($error->getMessage());
			}

			return null;
		}
		$this->setAttributesFromResult($moveResult);

		$driver->getRightsManager()->setAfterMove($this, $rightsCodesBeforeMove);

		$subscribersAfterMove = $subscriberManager->collectSubscribersSmart($this);
		$driver->getDeletedLogManager()->markAfterMove(
			$this,
			array_unique(array_diff($subscribersBeforeMove, $subscribersAfterMove)),
			$movedBy,
		);
		//notify new subscribers (in DeletedLog we notify subscribers only missed access)
		if($this instanceof Folder)
		{
			$driver->cleanCacheTreeBitrixDisk(array_keys($subscribersAfterMove));
		}
		$driver->sendChangeStatus($subscribersAfterMove);

		ObjectTable::updateSyncTime($this->id, new DateTime());
		$driver->sendChangeStatus($subscriberManager->collectSubscribersFromSubtree($this));

		return $this;
	}

	/**
	 * Simple logic: create copy in another storage and move in trash can.
	 * If we have problem - stop.
	 * Return new object.
	 * @param Folder $targetFolder
	 * @param int    $movedBy
	 * @return null|BaseObject
	 */
	protected function moveInAnotherStorage(Folder $targetFolder, $movedBy)
	{
		$newObject = $this->copyTo($targetFolder, $movedBy);
		if(!$newObject)
		{
			return null;
		}

		$newObject->update([
			'UPDATE_TIME'  => $this->getUpdateTime(),
			'CREATE_TIME'  => $this->getCreateTime(),
		]);

		if($newObject->getErrors())
		{
			$this->errorCollection->add($newObject->getErrors());
			return $newObject;
		}
		$rightsManager = Driver::getInstance()->getRightsManager();
		$specificRights = $rightsManager->getSpecificRights($this);
		$rightsManager->set($newObject, $specificRights);

		$this->markDeleted($movedBy);

		return $newObject;
	}

	/**
	 * Builds model from array.
	 * @param array $attributes Model attributes.
	 * @param array &$aliases Aliases.
	 * @internal
	 * @return static
	 */
	public static function buildFromArray(array $attributes, ?array &$aliases = null)
	{
		/** @var BaseObject $className */
		$className = static::getClassNameModel($attributes);
		/** @var BaseObject $model */
		$model = new $className();

		return $model->setAttributes($attributes, $aliases);
	}

	/**
	 * Builds model from \Bitrix\Main\Entity\Result.
	 * @param Result $result Query result.
	 * @return static
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public static function buildFromResult(Result $result)
	{
		$data = $result->getData();
		if($result instanceof AddResult)
		{
			$data['ID'] = $result->getId();
		}
		$className = static::getClassNameModel($data);
		/** @var BaseObject $model */
		$model = new $className();

		return $model->setAttributesFromResult($result);
	}

	protected static function getClassNameModel(array $row)
	{
		if(!isset($row['ID']))
		{
			throw new ArgumentTypeException('Invalid ID');
		}
		if(!isset($row['TYPE']))
		{
			throw new ArgumentTypeException('Invalid TYPE');
		}

		if(empty($row['REAL_OBJECT_ID']) || $row['REAL_OBJECT_ID'] == $row['ID'])
		{
			if($row['TYPE'] == ObjectTable::TYPE_FILE)
			{
				return File::className();
			}

			return Folder::className();
		}
		if($row['TYPE'] == ObjectTable::TYPE_FILE)
		{
			return FileLink::className();
		}

		return FolderLink::className();
	}

	/**
	 * Returns once model by specific filter.
	 * @param array $filter Filter.
	 * @param array $with List of eager loading.
	 * @throws \Bitrix\Main\NotImplementedException
	 * @return static
	 */
	public static function load(array $filter, array $with = [])
	{
		$objectData = static::getList([
			'with' => $with,
			'filter'=> $filter,
			'limit' => 1,
		])->fetch();

		if(empty($objectData))
		{
			return null;
		}
		/** @var BaseObject $className */
		$className = static::getClassNameModel($objectData);

		return $className::buildFromArray($objectData);
	}

	/**
	 * @param string $uniqueCode
	 * @param array $with
	 * @return File|null
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	public static function loadByUniqueCode(string $uniqueCode, array $with = []): ?static
	{
		/** @noinspection PhpUnhandledExceptionInspection */
		return static::load(['=UNIQUE_CODE' => $uniqueCode], $with);
	}

	/**
	 * Marks deleted object. It equals to move in trash can.
	 * @param int $deletedBy Id of user (or SystemUser::SYSTEM_USER_ID).
	 * @return bool
	 */
	abstract public function markDeleted($deletedBy);

	/**
	 * Restores object from trash can.
	 * @param int $restoredBy Id of user (or SystemUser::SYSTEM_USER_ID).
	 * @return bool
	 */
	abstract public function restore($restoredBy);

	/**
	 * Restores object under link and destroy itself.
	 * It's special case. @see \Bitrix\Disk\BaseObject::markDeletedInternal.
	 * There we put file/folder in original trash can and in trash can of user, who delete the object.
	 *
	 * @param int $restoredBy Id of user (or SystemUser::SYSTEM_USER_ID).
	 * @return bool
	 */
	protected function restoreByLinkObject($restoredBy)
	{
		if ($this->deletedType != ObjectTable::DELETED_TYPE_ROOT)
		{
			$this->errorCollection[] = new Error(
				"It's not possible to restore object, which was deleted with TYPE_CHILD",
				self::ERROR_RESTORE_UNDER_LINK_WRONG_TYPE,
			);

			return false;
		}

		$realObject = $this->getRealObject();
		if (!$realObject)
		{
			$this->errorCollection[] = new Error('Could not find real object of link to restore it');

			return false;
		}

		$status = $realObject->restore($restoredBy);
		$this->deleteInternal();

		return $status;
	}

	/**
	 * @param int $deletedBy
	 * @param int $deletedType
	 * @throws \Bitrix\Main\ArgumentException
	 * @return bool
	 */
	protected function markDeletedInternal($deletedBy, $deletedType = ObjectTable::DELETED_TYPE_ROOT)
	{
		if ($this->deletedType == $deletedType)
		{
			return true;
		}

		$this->errorCollection->clear();

		$subscriberManager = Driver::getInstance()->getSubscriberManager();
		foreach($subscriberManager->getSharingsByObject($this) as $sharing)
		{
			$sharing->delete($deletedBy);
		}

		//with status unreplied, declined (not approved)
		SharingTable::deleteByFilter([
			'REAL_OBJECT_ID' => $this->id,
		]);

		if ($deletedType == ObjectTable::DELETED_TYPE_CHILD)
		{
			$nameAfterDelete = $this->getNameWithoutTrashCanSuffix();
		}
		elseif ($deletedType == ObjectTable::DELETED_TYPE_ROOT)
		{
			//we want to delete object as root. It means it has to have unique name. In this way we clean the name,
			//and after we make it unique.
			$nameAfterDelete = Ui\Text::appendTrashCanSuffix($this->getNameWithoutTrashCanSuffix());
		}
		else
		{
			$nameAfterDelete = $this->getNameWithoutTrashCanSuffix();
		}

		$nameService = new ObjectNameService($nameAfterDelete, $this->getParentId(), $this->getType());
		$nameService->requireUniqueName();
		$nameService->excludeId($this->getId());

		$result = $nameService->prepareName();
		if (!$result->isSuccess())
		{
			$this->errorCollection->add($result->getErrors());

			return false;
		}

		$nameAfterDelete = $result->getName();

		$data = [
			'CODE' => null,
			'NAME' => $nameAfterDelete,
			'DELETED_TYPE' => $deletedType,
			// trashing changes the object for a sync client the way restoreInternal() does, so it moves
			// the sync mark too; written here because the ORM hook raises it only along with UPDATE_TIME
			'SYNC_UPDATE_TIME' => new DateTime(),
		];

		$alreadyDeleted = $this->isDeleted();
		if (!$alreadyDeleted)
		{
			$data['DELETE_TIME'] = new DateTime();
			$data['DELETED_BY'] = $deletedBy;
		}

		$status = $this->update($data);
		if ($status)
		{
			$driver = Driver::getInstance();
			$driver->getDeletionNotifyManager()->put($this, $deletedBy);
			$driver->getIndexManager()->dropIndexByModuleSearch($this);
			$this->publishToJournal(
				ChangeType::Trashed,
				$deletedBy,
				['name', 'places'],
				isCascadeRoot: (int)$deletedType === ObjectTable::DELETED_TYPE_ROOT,
			);

			$event = new Event(Driver::INTERNAL_MODULE_ID, "onAfterMarkDeletedObject", [$this, $deletedBy, $deletedType]);
			$event->send();
		}

		if ($deletedType == ObjectTable::DELETED_TYPE_ROOT)
		{
			$this->createLinkInTrashcan($deletedBy);
		}

		return $status;
	}

	private function createLinkInTrashcan($deletedBy)
	{
		$userStorage = Driver::getInstance()->getStorageByUserId($deletedBy);
		if (!$userStorage || $userStorage->getId() == $this->getStorageId())
		{
			return;
		}

		if ($this instanceof File)
		{
			$userStorage->addFileLink(
				$this,
				[
					'CREATED_BY' => $deletedBy,
					'DELETED_BY' => $deletedBy,
					'DELETED_TYPE' => ObjectTable::DELETED_TYPE_ROOT,
					'DELETE_TIME' => new DateTime(),
				],
			);
		}
		elseif ($this instanceof Folder)
		{
			$userStorage->addFolderLink(
				$this,
				[
					'CREATED_BY' => $deletedBy,
					'DELETED_BY' => $deletedBy,
					'DELETED_TYPE' => ObjectTable::DELETED_TYPE_ROOT,
					'DELETE_TIME' => new DateTime(),
				],
			);
		}
	}

	/**
	 * Restores object from trash can.
	 * @param int $restoredBy Id of user (or SystemUser::SYSTEM_USER_ID).
	 * @return bool
	 * @internal
	 */
	public function restoreInternal($restoredBy)
	{
		$nameService = new ObjectNameService($this->getNameWithoutTrashCanSuffix(), $this->parentId, $this->getType());
		$nameService->requireUniqueName();
		$nameService->excludeId($this->id);

		$result = $nameService->prepareName();
		if (!$result->isSuccess())
		{
			$this->errorCollection->add($result->getErrors());

			return false;
		}

		$this->name = $result->getName();
		/** @var ObjectTable $tableClassName */
		$tableClassName = $this->getTableClassName();

		$status = $this->update([
			'NAME' => $this->getNameWithoutTrashCanSuffix(),
			'DELETED_TYPE' => $tableClassName::DELETED_TYPE_NONE,
			'UPDATE_TIME' => new DateTime(),
			'UPDATED_BY' => $restoredBy,
		]);

		if($status)
		{
			//we have to delete links which are in trashcan. It's special case. @see \Bitrix\Disk\BaseObject::markDeletedInternal.
			$links = BaseObject::getModelList([
				'filter' => [
					'REAL_OBJECT_ID' => $this->id,
					'!=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
				],
			]);
			foreach($links as $link)
			{
				if ($link instanceof FileLink)
				{
					$link->delete($restoredBy);
				}
				elseif ($link instanceof FolderLink)
				{
					$link->deleteTree($restoredBy);
				}
			}

			$this->publishToJournal(
				ChangeType::Restored,
				$restoredBy,
				['name', 'places'],
				isCascadeRoot: !$this->journalCascadeChild,
			);

			$event = new Event(Driver::INTERNAL_MODULE_ID, "onAfterRestoreObject", [$this, $restoredBy]);
			$event->send();
		}

		return $status;
	}

	protected function recalculateDeletedTypeAfterRestore($restoredBy)
	{
		$fakeContext = Storage::getFakeSecurityContext();
		$parents = $this->getParents($fakeContext, ['filter' => ['MIXED_SHOW_DELETED' => true]], SORT_ASC);
		foreach ($parents as $parent)
		{
			if(!$parent instanceof Folder || !$parent->isDeleted())
			{
				continue;
			}

			/** @var $parent Folder */
			foreach ($parent->getChildren($fakeContext, ['filter' => ['!==DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE]]) as $childPotentialRoot)
			{
				if($childPotentialRoot->getId() == $this->getId())
				{
					continue;
				}
				if($childPotentialRoot instanceof Folder)
				{
					/** @var $childPotentialRoot Folder */
					$childPotentialRoot->markDeletedNonRecursiveInternal($childPotentialRoot->getDeletedBy());
				}
				elseif($childPotentialRoot instanceof File)
				{
					$childPotentialRoot->markDeletedInternal($childPotentialRoot->getDeletedBy());
				}
			}
		}

		foreach ($parents as $parent)
		{
			if (!$parent instanceof Folder || !$parent->isDeleted())
			{
				continue;
			}

			$parent->restoreNonRecursive($restoredBy);
		}
	}

	/**
	 * Reports a change of this object to the journal of changes read by disk.sync.changes.list.
	 *
	 * The call belongs where the operation already tells search, recents, the realtime channel and the
	 * subscribers about itself: only there are the meaning of the operation and the root of its cascade
	 * both known.
	 *
	 * @param string[]|null $changedFields the fields of the card
	 *        {@see \Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ObjectCardDto} the operation is about,
	 *        not every column its statement touched
	 */
	protected function publishToJournal(
		ChangeType $changeType,
		?int $authorId = null,
		?array $changedFields = null,
		bool $isCascadeRoot = true,
	): void
	{
		$this->getJournalWriter()->publishObjectChange($this, $changeType, $authorId, $changedFields, $isCascadeRoot);
	}

	/**
	 * The audience of the tombstone of this object, taken before the deletion tears down the rights and
	 * the interaction set it is read from.
	 *
	 * @return string[]|null
	 */
	protected function captureTombstoneAudience(): ?array
	{
		return $this->getJournalWriter()->captureTombstoneAudience($this);
	}

	/**
	 * Reports the disappearance of this object from the portal.
	 *
	 * A tombstone tells the client to forget the object, so the call belongs after the deletion has
	 * succeeded and not before it. The audience is the one {@see captureTombstoneAudience()} took while
	 * the object was still whole: read here it would be empty, and a record without a single code is not
	 * published at all.
	 *
	 * @param string[]|null $audienceCodes
	 */
	protected function publishTombstoneToJournal(?int $authorId = null, ?array $audienceCodes = null): void
	{
		$this->getJournalWriter()->publishTombstone($this, TombstoneReason::Deleted, $authorId, $audienceCodes);
	}

	protected function getJournalWriter(): JournalWriter
	{
		return $this->journalWriter ??= new JournalWriter();
	}

	/**
	 * Returns list parents of object.
	 * @param SecurityContext $securityContext Security context.
	 * @param array           $parameters Parameters.
	 * @param int             $orderDepthLevel Order for depth level (default asc).
	 * @return array|Folder[]|File[]|FileLink[]|FolderLink[]
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public function getParents(SecurityContext $securityContext, array $parameters = [], $orderDepthLevel = SORT_ASC)
	{
		if(!isset($parameters['filter']))
		{
			$parameters['filter'] = [];
		}
		if(!isset($parameters['select']))
		{
			$parameters['select'] = ['*'];
		}

		if(!empty($parameters['filter']['MIXED_SHOW_DELETED']))
		{
			unset($parameters['filter']['DELETED_TYPE'], $parameters['filter']['MIXED_SHOW_DELETED']);
		}
		elseif (
			!array_key_exists('DELETED_TYPE', $parameters['filter'])
			&& !array_key_exists('!DELETED_TYPE', $parameters['filter'])
			&& !array_key_exists('!=DELETED_TYPE', $parameters['filter'])
			&& !array_key_exists('!==DELETED_TYPE', $parameters['filter'])
		)
		{
			$parameters['filter']['DELETED_TYPE'] = ObjectTable::DELETED_TYPE_NONE;
		}
		$parameters['select']['DEPTH_LEVEL'] = 'PATH_PARENT.DEPTH_LEVEL';
		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck($securityContext, $parameters, ['ID', 'CREATED_BY']);

		/** @var ObjectTable $tableClassName */
		$tableClassName = $this->getTableClassName();
		$data = $tableClassName::getAncestors($this->id, static::prepareGetListParameters($parameters))->fetchAll();
		Collection::sortByColumn($data, ['DEPTH_LEVEL' => $orderDepthLevel]);

		$modelData = [];
		foreach($data as $item)
		{
			$modelData[] = BaseObject::buildFromArray($item);
		}
		unset($item);

		return $modelData;
	}

	/**
	 * Returns parent model.
	 * If object does not have parent, then returns null.
	 * @return Folder|null
	 * @throws \Bitrix\Main\NotImplementedException
	 */
	public function getParent()
	{
		if(!$this->parentId)
		{
			return null;
		}

		if($this->isLoadedAttribute('parent') && $this->parent && $this->parentId === $this->parent->getId())
		{
			return $this->parent;
		}
		//todo - BaseObject - knows about Folder ^( Nu i pust'
		$this->parent = Folder::loadById($this->getParentId());
		$this->setAsLoadedAttribute('parent');

		return $this->parent;
	}

	/**
	 * Tells if object is root folder.
	 * @return bool
	 */
	public function isRoot()
	{
		return !$this->parentId && $this instanceof Folder && !$this->isLink();
	}

	/**
	 * Tells if name is not unique in object.
	 * @param string $name Name.
	 * @param int $underObjectId Id of parent object.
	 * @param null $excludeId Id which will be excluded from query.
	 * @param null &$opponentId Opponent object which has same name.
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public static function isUniqueName($name, $underObjectId, $excludeId = null, &$opponentId = null): bool
	{
		$nameService = new ObjectNameService($name, $underObjectId);
		if ($excludeId !== null)
		{
			$nameService->excludeId($excludeId);
		}
		$shouldReturnOpponentId = \func_num_args() >= 4;

		$isUnique = $nameService->isUniqueName(
			$excludeId,
			$shouldReturnOpponentId,
		);

		if (($shouldReturnOpponentId === false) || $isUnique->isSuccess())
		{
			return $isUnique->isSuccess();
		}

		$opponentId = $isUnique->getData()['opponentId'] ?? null;

		return false;
	}

	protected function updateLinksAttributes(array $attr)
	{
		/** @var ObjectTable $tableClassName */
		$tableClassName = $this->getTableClassName();
		//todo don't update object with REAL_OBJECT_ID == ID. Exlucde form update. It is not necessary.
		$tableClassName::updateAttributesByFilter($attr, ['REAL_OBJECT_ID' => $this->id]);

		ModelSynchronizer::getInstance()->trigger($this, $attr);
	}

	/**
	 * Returns all sharings where the object is as source (real_object_id).
	 *
	 * @param array{TO_ENTITY: string} $options
	 * @return Sharing[]
	 */
	public function getSharingsAsReal(array $options = [])
	{
		$filter = [
			'REAL_OBJECT_ID' => $this->id,
			'REAL_STORAGE_ID' => $this->storageId,
			'!=STATUS' => SharingTable::STATUS_IS_DECLINED,
		];
		if (isset($options['TO_ENTITY']))
		{
			$filter['=TO_ENTITY'] = $options['TO_ENTITY'];
		}

		$sharings = Sharing::getModelList([
			'with' => ['LINK_OBJECT'],
			'filter' => $filter,
		]);
		foreach($sharings as $sharing)
		{
			$sharing->setAttributes(['REAL_OBJECT' => $this]);
		}

		return $sharings;
	}

	/**
	 * Returns all sharing where the object is as link (link_object_id).
	 * @return Sharing[]
	 * @throws \Bitrix\Main\NotImplementedException
	 */
	public function getSharingsAsLink()
	{
		/** @var Sharing[] $sharings */
		$sharings = Sharing::getModelList([
			'filter' => [
				'LINK_OBJECT_ID' => $this->id,
				'LINK_STORAGE_ID' => $this->storageId,
			],
		]);
		foreach($sharings as $sharing)
		{
			$sharing->setAttributes(['LINK_OBJECT' => $this]);
		}
		unset($sharing);

		return $sharings;
	}

	/**
	 * Returns list users who have sharing on this object.
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\LoaderException
	 */
	public function getMembersOfSharing()
	{
		$realObject = $this->getRealObject();
		if(!$realObject)
		{
			return [];
		}

		$sharings = $realObject->getSharingsAsReal();
		$members = [];
		$membersToSharing = [];
		foreach($sharings as $sharing)
		{
			if($sharing->isToDepartmentChild())
			{
				continue;
			}
			[$type, $id] = Sharing::parseEntityValue($sharing->getToEntity());
			$members[$type][] = $id;
			$membersToSharing[$type . '|' . $id] = $sharing;
		}
		unset($sharing);

		$enabledSocialnetwork = Loader::includeModule('socialnetwork');

		$entityList = [];
		foreach(SharingTable::getListOfTypeValues() as $type)
		{
			if(empty($members[$type]))
			{
				continue;
			}
			if($type == SharingTable::TYPE_TO_USER)
			{
				// UF_DEPARTMENT is the condition by which User::buildFromArray() resolves the model
				// flags from the row; the values read are EXTERNAL_AUTH_ID and UF_USER_CRM_ENTITY
				$select = array(
					'ID', 'PERSONAL_PHOTO', 'NAME', 'LOGIN', 'LAST_NAME', 'SECOND_NAME',
					'UF_DEPARTMENT', 'EXTERNAL_AUTH_ID',
				);
				if (ModuleManager::isModuleInstalled('crm'))
				{
					$select[] = 'UF_USER_CRM_ENTITY';
				}

				$query = UserTable::getList(array(
					'select' => $select,
					'filter' => array('ID' => array_values($members[$type])),
				));
				while($userRow = $query->fetch())
				{
					/** @var User $userModel */
					$userModel = User::buildFromRow($userRow);
					/** @var Sharing $sharing */
					$sharing = $membersToSharing[$type . '|' . $userRow['ID']];
					$entityList[] = [
						'sharingId' => $sharing->getId(),
						'entityId' => Sharing::CODE_USER . $userRow['ID'],
						'name' => $userModel->getFormattedName(),
						'right' => $sharing->getTaskName(),
						'avatar' => $userModel->getAvatarSrc(),
						'ownAvatar' => $userModel->getOwnAvatarSrc(),
						'url' => $userModel->getDetailUrl(),
						'type' => 'users',
						'memberType' => SharingMemberType::forUser($userModel)?->value,
					];
				}
			}
			elseif($type == SharingTable::TYPE_TO_GROUP && $enabledSocialnetwork)
			{
				$query = \CSocNetGroup::getList([], ['ID' => array_values($members[$type])], false, false, [
					'ID',
					'IMAGE_ID',
					'NAME',
				]);
				while($query && $groupRow = $query->fetch())
				{
					/** @var Sharing $sharing */
					$sharing = $membersToSharing[$type . '|' . $groupRow['ID']];
					$entityList[] = [
						'sharingId' => $sharing->getId(),
						'entityId' => Sharing::CODE_SOCNET_GROUP . $groupRow['ID'],
						'name' => Emoji::decode($groupRow['NAME']),
						'right' => $sharing->getTaskName(),
						'avatar' => Avatar::getGroup($groupRow['IMAGE_ID']),
						'ownAvatar' => Avatar::getSrc($groupRow['IMAGE_ID']) ?: null,
						'type' => 'groups',
						'memberType' => SharingMemberType::Group->value,
					];
				}
			}
			elseif($type == SharingTable::TYPE_TO_DEPARTMENT && $enabledSocialnetwork)
			{
				// intranet structure
				$structure = \CSocNetLogDestination::getStucture();
				foreach(array_values($members[$type]) as $departmentId)
				{
					if(empty($structure['department']['DR' . $departmentId]))
					{
						continue;
					}
					/** @var Sharing $sharing */
					$sharing = $membersToSharing[$type . '|' . $departmentId];
					$entityList[] = [
						'sharingId' => $sharing->getId(),
						'entityId' => Sharing::CODE_DEPARTMENT . $departmentId,
						'name' => $structure['department']['DR' . $departmentId]['name'],
						'right' => $sharing->getTaskName(),
						'avatar' => Avatar::getDefaultGroup(),
						'ownAvatar' => null,
						'type' => 'department',
						'memberType' => SharingMemberType::Department->value,
					];
				}
				unset($departmentId);
			}
			elseif($type == SharingTable::TYPE_TO_CHAT)
			{
				$chatNames = $this->getChatNames(array_values($members[$type]));
				foreach ($chatNames as $chat)
				{
					/** @var Sharing $sharing */
					$sharing = $membersToSharing[$type . '|' . $chat['id']];
					$entityList[] = [
						'sharingId' => $sharing->getId(),
						'entityId' => $sharing->getToEntity(),
						'name' => $chat['title'],
						'right' => $sharing->getTaskName(),
						'avatar' => Avatar::getDefaultGroup(),
						'ownAvatar' => null,
						'type' => 'chat',
						'memberType' => null,
					];
				}
			}
		}

		return $entityList;
	}

	private function getChatNames(array $ids): array
	{
		if (!Loader::includeModule('im'))
		{
			return [];
		}

		$result = [];
		$chats = ChatTable::getList([
			'select' => ['ID', 'TITLE'],
			'filter' => ['=ID' => $ids],
		]);

		foreach ($chats as $chat)
		{
			$result[$chat['ID']] = [
				'id' => $chat['ID'],
				'title' => $chat['TITLE'],
			];
		}

		return $result;
	}

	/**
	 * Returns object ttl model.
	 *
	 * @return ObjectTtl
	 */
	public function getTtl()
	{
		if($this->isLoadedAttribute('ttl'))
		{
			return $this->ttl;
		}
		$this->ttl = ObjectTtl::load(['OBJECT_ID' => $this->id]);
		$this->setAsLoadedAttribute('ttl');

		return $this->ttl;
	}

	/**
	 * Creates ttl object.
	 *
	 * @param int $ttl Seconds to live.
	 * @return ObjectTtl
	 */
	public function setTtl($ttl)
	{
		$ttl = (int)$ttl;
		$deathTime = DateTime::createFromTimestamp(time() + $ttl);

		$objectTtl = ObjectTtl::loadByObjectId($this->id);
		if ($objectTtl)
		{
			if($objectTtl->getDeathTime()->getTimestamp() > $deathTime->getTimestamp())
			{
				$objectTtl->changeDeathTime($deathTime);

				return $objectTtl;
			}
			else
			{
				return $objectTtl;
			}
		}

		return ObjectTtl::add(
			[
				'OBJECT_ID' => $this->id,
				'DEATH_TIME' => $deathTime,
			],
			$this->errorCollection,
		);
	}

	public function makeObjectEvent(string $category, array $data = []): ObjectEvent
	{
		return new ObjectEvent($this, $category, $data);
	}

	public function getObjectOptions(): array
	{
		if (is_null($this->objectOptions))
		{
			$options = [
				ObjectOptionsTable::NAME_ALLOW_DOWNLOAD_ON_READ => true,
				ObjectOptionsTable::NAME_ALLOW_MANAGE_PUBLIC_ACCESS_ON_READ => false,
				ObjectOptionsTable::NAME_EXTERNAL_LINK_SCENARIO => null,
			];

			$items = ObjectOptionsTable::getList([
				'select' => ['NAME', 'VALUE'],
				'filter' => ['=OBJECT_ID' => $this->getId()],
			]);

			$items = $items->fetchAll();

			foreach ($items as $item)
			{
				if (array_key_exists($item['NAME'], $options) && $item['VALUE'])
				{
					$options[$item['NAME']] = json_decode($item['VALUE']);
				}
			}

			$this->objectOptions = $options;
		}

		return $this->objectOptions;
	}

	public function isAllowManagePublicAccessOnRead(): bool
	{
		return (bool)$this->getObjectOptions()[ObjectOptionsTable::NAME_ALLOW_MANAGE_PUBLIC_ACCESS_ON_READ];
	}

	public function canManageExternalLink(SecurityContext $securityContext): bool
	{
		if (!$this->canRead($securityContext))
		{
			return false;
		}

		return $this->canUpdate($securityContext) || $this->isAllowManagePublicAccessOnRead();
	}

	public function setObjectOption($name, $value): self
	{
		$options = [
			ObjectOptionsTable::NAME_ALLOW_DOWNLOAD_ON_READ,
			ObjectOptionsTable::NAME_ALLOW_MANAGE_PUBLIC_ACCESS_ON_READ,
			ObjectOptionsTable::NAME_EXTERNAL_LINK_SCENARIO,
		];
		if (!in_array($name, $options))
		{
			throw new \Bitrix\Main\ArgumentException("Option is not allowed", "name");
		}

		$items = ObjectOptionsTable::getList([
			'filter' => [
				'=OBJECT_ID' => $this->getId(),
				'=NAME' => $name,
			],
		]);
		$item = $items->fetchObject();
		if (!$item)
		{
			$item = new \Bitrix\Disk\Internals\EO_ObjectOptions();
			$item->setObjectId($this->getId());
			$item->setName($name);
		}
		$item->setValue(json_encode($value));
		$item->save();
		$this->objectOptions = null;

		return $this;
	}

	/**
	 * Returns the list of pair for mapping.
	 * Key is field in DataManager, value is object property.
	 * @return array
	 */
	public static function getMapAttributes()
	{
		return [
			'ID' => 'id',
			'NAME' => 'name',
			'CODE' => 'code',
			'XML_ID' => 'xmlId',
			'STORAGE_ID' => 'storageId',
			'STORAGE' => 'storage',
			'TYPE' => 'type',
			'REAL_OBJECT_ID' => 'realObjectId',
			'REAL_OBJECT' => 'realObject',
			'PARENT_ID' => 'parentId',
			'PARENT' => 'parent',
			'LOCK' => 'lock',
			'TTL' => 'ttl',
			'CONTENT_PROVIDER' => 'contentProvider',
			'DELETED_TYPE' => 'deletedType',
			'TYPE_FILE' => null,
			'GLOBAL_CONTENT_VERSION' => null,
			'FILE_ID' => null,
			'SIZE' => null,
			'EXTERNAL_HASH' => null,
			'ETAG' => null,
			'CREATE_TIME' => 'createTime',
			'UPDATE_TIME' => 'updateTime',
			'SYNC_UPDATE_TIME' => 'syncUpdateTime',
			'DELETE_TIME' => 'deleteTime',
			'CREATED_BY' => 'createdBy',
			'CREATE_USER' => 'createUser',
			'UPDATED_BY' => 'updatedBy',
			'UPDATE_USER' => 'updateUser',
			'DELETED_BY' => 'deletedBy',
			'DELETE_USER' => 'deleteUser',
			'HAS_SUBFOLDERS' => null,
			'UNIQUE_CODE' => 'uniqueCode',
		];
	}

	/**
	 * Returns the list attributes which is connected with another models.
	 * @return array
	 */
	public static function getMapReferenceAttributes()
	{
		$userClassName = User::className();
		$fields = User::getFieldsForSelect();

		return [
			'CREATE_USER' => [
				'class' => $userClassName,
				'select' => $fields,
			],
			'UPDATE_USER' => [
				'class' => $userClassName,
				'select' => $fields,
			],
			'DELETE_USER' => [
				'class' => $userClassName,
				'select' => $fields,
			],
			'REAL_OBJECT' => BaseObject::className(),
			'STORAGE' => Storage::className(),
			'LOCK' => ObjectLock::className(),
			'TTL' => ObjectTtl::className(),
		];
	}

	public function supportsUnifiedLink(): bool
	{
		$realObject = $this->getRealObject();

		if ($realObject === null)
		{
			return false;
		}

		$unifiedLinkSupportService = ServiceLocator::getInstance()->get(UnifiedLinkSupportService::class);

		return $unifiedLinkSupportService->supports($realObject);
	}

	/**
	 * Specify data which should be serialized to JSON
	 * @link http://php.net/manual/en/jsonserializable.jsonserialize.php
	 * @return mixed data which can be serialized by <b>json_encode</b>,
	 * which is a value of any type other than a resource.
	 * @since 5.4.0
	 */
	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->getId(),
			'name' => $this->getName(),
			'createTime' => $this->getCreateTime(),
			'updateTime' => $this->getUpdateTime(),
			'deleteTime' => $this->getDeleteTime(),
			'code' => $this->getCode(),
			'xmlId' => $this->getXmlId(),
			'storageId' => (int)$this->getStorageId(),
			'realObjectId' => (int)$this->getRealObjectId(),
			'parentId' => (int)$this->getParentId(),
			'deletedType' => (int)$this->getDeletedType(),
			'createdBy' => $this->getCreatedBy(),
			'updatedBy' => $this->getUpdatedBy(),
			'deletedBy' => $this->getDeletedBy(),
			'uniqueCode' => $this->getUniqueCode(),
		];
	}
}
