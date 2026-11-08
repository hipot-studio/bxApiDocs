<?php

namespace Bitrix\Disk\Document\Models;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\SavingMarker;
use Bitrix\Disk\Internals\Entity\Model;
use Bitrix\Disk\User;
use Bitrix\Disk\Version;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;

/**
 * @method File|null getObject()
 * @method Version|null getVersion()
 * @method \Bitrix\Disk\EmptyUser|\Bitrix\Disk\SystemUser|User getOwner()
 */
final class DocumentInfo extends Model
{
	public const CONTENT_STATUS_INIT = DocumentInfoTable::CONTENT_STATUS_INIT;
	public const CONTENT_STATUS_EDITING = DocumentInfoTable::CONTENT_STATUS_EDITING;
	public const CONTENT_STATUS_FORCE_SAVED_WITH_ERROR = DocumentInfoTable::CONTENT_STATUS_FORCE_SAVED_WITH_ERROR;
	public const CONTENT_STATUS_FORCE_SAVED = DocumentInfoTable::CONTENT_STATUS_FORCE_SAVED;
	public const CONTENT_STATUS_SAVED_WITH_ERROR = DocumentInfoTable::CONTENT_STATUS_SAVED_WITH_ERROR;
	public const CONTENT_STATUS_SAVED = DocumentInfoTable::CONTENT_STATUS_SAVED;
	public const CONTENT_STATUS_NO_CHANGES = DocumentInfoTable::CONTENT_STATUS_NO_CHANGES;
	public const CONTENT_STATUS_SAVE_REJECTED = DocumentInfoTable::CONTENT_STATUS_SAVE_REJECTED;
	public const CONTENT_STATUS_INTERRUPTED = DocumentInfoTable::CONTENT_STATUS_INTERRUPTED;

	public const SECONDS_TO_MARK_AS_STILL_WORKING = 60;

	public const REF_OWNER = 'owner';
	public const REF_OBJECT = 'object';
	public const REF_VERSION = 'version';

	/** @var string */
	protected $externalHash;
	/** @var int */
	protected $objectId;
	/** @var int */
	protected $versionId;
	/** @var int */
	protected $ownerId;
	/** @var DateTime */
	protected $createTime;
	/** @var DateTime */
	protected $updateTime;
	/** @var int */
	protected $users;
	/** @var int */
	protected $contentStatus;

	/**
	 * Gets the fully qualified name of table class which belongs to current model.
	 * @return string
	 */
	public static function getTableClassName()
	{
		return DocumentInfoTable::class;
	}

	/**
	 * @return int
	 */
	public function getObjectId(): ?int
	{
		return $this->objectId;
	}

	public function getFile(): ?File
	{
		return $this->getObject();
	}

	/**
	 * @return int
	 */
	public function getVersionId(): ?int
	{
		return $this->versionId;
	}

	public function isVersion(): bool
	{
		return (bool)$this->getVersionId();
	}

	public function getFilename(): string
	{
		if ($this->isVersion())
		{
			$version = $this->getVersion();

			return $version ? $version->getName() : '';
		}

		$file = $this->getObject();

		return $file ? $file->getName() : '';
	}

	/**
	 * @return int
	 */
	public function getOwnerId(): int
	{
		return $this->ownerId;
	}

	/**
	 * @return string
	 */
	public function getExternalHash(): string
	{
		return $this->externalHash;
	}

	/**
	 * @return DateTime
	 */
	public function getCreateTime(): DateTime
	{
		return $this->createTime;
	}

	/**
	 * @return DateTime
	 */
	public function getUpdateTime(): DateTime
	{
		return $this->updateTime;
	}

	/**
	 * @return int
	 */
	public function getUserCount(): int
	{
		return $this->users;
	}

	public function setUserCount(int $count): bool
	{
		return $this->update([
			'USERS' => $count,
		]);
	}

	/**
	 * @return int
	 */
	public function getContentStatus(): int
	{
		return $this->contentStatus;
	}

	/**
	 * Whether the session behind the document is over, whatever it ended with. A rejected save and an
	 * interrupted session end it too: the document is released, and the page waiting for it may go on.
	 */
	public function isFinished(): bool
	{
		$finalStatuses = [
			self::CONTENT_STATUS_SAVED_WITH_ERROR,
			self::CONTENT_STATUS_SAVED,
			self::CONTENT_STATUS_NO_CHANGES,
			self::CONTENT_STATUS_SAVE_REJECTED,
			self::CONTENT_STATUS_INTERRUPTED,
		];

		return in_array($this->getContentStatus(), $finalStatuses, true);
	}

	public function wasFinallySaved(): bool
	{
		return $this->getContentStatus() === self::CONTENT_STATUS_SAVED;
	}

	public function wasForceSaved(): bool
	{
		$forceSavedStatuses = [
			self::CONTENT_STATUS_FORCE_SAVED,
			self::CONTENT_STATUS_FORCE_SAVED_WITH_ERROR,
		];

		return in_array($this->getContentStatus(), $forceSavedStatuses, true);
	}

	/**
	 * Whether the editing behind the key is gone for good and the next opening needs a key of its own.
	 *
	 * The silence of the editor server is the only evidence here, and a webhook writing the version is
	 * silent in exactly the same way; handing out a new key while it works loses the version it writes.
	 * The mark of a save in progress tells the two apart, and it is asked last: it costs a query to the
	 * storage, and every cheaper reason to keep the key is already spent by then.
	 */
	public function isAbandoned(): bool
	{
		if ($this->isFinished())
		{
			return false;
		}

		if (!$this->isSaving())
		{
			return false;
		}

		$now = new DateTime();
		if ($now->getTimestamp() - $this->getUpdateTime()->getTimestamp() <= self::SECONDS_TO_MARK_AS_STILL_WORKING * 2)
		{
			return false;
		}

		$savingMarker = ServiceLocator::getInstance()->get(SavingMarker::class);

		return !$savingMarker->isActive($this->getExternalHash());
	}

	public function isSaving(): bool
	{
		if ($this->getUserCount() !== 0)
		{
			return false;
		}

		if ($this->getContentStatus() === self::CONTENT_STATUS_EDITING)
		{
			return true;
		}

		return false;
	}

	public function markAsEditing(): bool
	{
		return $this->markWithStatus(self::CONTENT_STATUS_EDITING);
	}

	public function markAsNoChanges(): bool
	{
		return $this->markWithStatus(self::CONTENT_STATUS_NO_CHANGES);
	}

	public function markAsSaved(): bool
	{
		return $this->markWithStatus(self::CONTENT_STATUS_SAVED);
	}

	public function markAsSavedWithError(): bool
	{
		return $this->markWithStatus(self::CONTENT_STATUS_SAVED_WITH_ERROR);
	}

	public function markAsSaveRejected(): bool
	{
		return $this->markWithStatus(self::CONTENT_STATUS_SAVE_REJECTED);
	}

	public function markAsForceSaved(): bool
	{
		return $this->markWithStatus(self::CONTENT_STATUS_FORCE_SAVED);
	}

	public function markAsForceSavedWithError(): bool
	{
		return $this->markWithStatus(self::CONTENT_STATUS_FORCE_SAVED_WITH_ERROR);
	}

	protected function markWithStatus(int $contentStatus): bool
	{
		if ($this->getContentStatus() === $contentStatus)
		{
			return true;
		}

		return $this->update([
			'CONTENT_STATUS' => $contentStatus,
		]);
	}

	public function actualizeUpdateTime(): bool
	{
		return $this->update([
			'UPDATE_TIME' => new DateTime(),
	 	]);
	}

	protected function update(array $data)
	{
		if (!isset($data['UPDATE_TIME']))
		{
			$data['UPDATE_TIME'] = new DateTime();
		}

		return parent::update($data);
	}

	public function getPrimary()
	{
		return $this->getExternalHash();
	}

	public static function loadById($id, array $with = [])
	{
		return static::load(['=EXTERNAL_HASH' => $id], $with);
	}

	public static function getMapAttributes()
	{
		return [
			'EXTERNAL_HASH' => 'externalHash',
			'OBJECT_ID' => 'objectId',
			'VERSION_ID' => 'versionId',
			'OWNER_ID' => 'ownerId',
			'CREATE_TIME' => 'createTime',
			'UPDATE_TIME' => 'updateTime',
			'USERS' => 'users',
			'CONTENT_STATUS' => 'contentStatus',
		];
	}

	/**
	 * Returns the list attributes which is connected with another models.
	 *
	 * @return array
	 */
	public static function getMapReferenceAttributes()
	{
		$fields = User::getFieldsForSelect();

		return [
			self::REF_OWNER => [
				'class' => User::class,
				'select' => $fields,
				'load' => function(self $info){
					return User::loadById($info->getOwnerId());
				},
			],
			self::REF_OBJECT => [
				'class' => File::class,
				'load' => function(self $info){
					return File::loadById($info->getObjectId());
				},
			],
			self::REF_VERSION => [
				'class' => Version::class,
				'load' => function(self $info){
					return Version::loadById($info->getVersionId());
				},
			],
		];
	}
}