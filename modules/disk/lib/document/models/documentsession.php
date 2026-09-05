<?php

namespace Bitrix\Disk\Document\Models;

use Bitrix\Disk\Document\OnlyOffice\Bitrix24Scenario;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\Entity\Model;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\User;
use Bitrix\Disk\Version;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Type\DateTime;

/**
 * @method File|null getObject()
 * @method Version|null getVersion()
 * @method DocumentInfo|null getInfo()
 * @method \Bitrix\Disk\EmptyUser|\Bitrix\Disk\SystemUser|User getUser()
 * @method \Bitrix\Disk\EmptyUser|\Bitrix\Disk\SystemUser|User getOwner()
 */
final class DocumentSession extends Model
{
	public const TYPE_VIEW = DocumentSessionTable::TYPE_VIEW;
	public const TYPE_EDIT = DocumentSessionTable::TYPE_EDIT;

	public const STATUS_ACTIVE = DocumentSessionTable::STATUS_ACTIVE;
	public const STATUS_NON_ACTIVE = DocumentSessionTable::STATUS_NON_ACTIVE;
	public const STATUS_BROKEN = DocumentSessionTable::STATUS_BROKEN;

	public const REF_USER = 'user';
	public const REF_OWNER = 'owner';
	public const REF_OBJECT = 'object';
	public const REF_VERSION = 'version';
	public const REF_INFO = 'info';

	/** @var int */
	protected $id;
	/** @var int */
	protected $objectId;
	/** @var int */
	protected $versionId;
	/** @var int */
	protected $userId;
	/** @var int */
	protected $ownerId;
	/** @var bool */
	protected $isExclusive;
	/** @var string */
	protected $externalHash;
	/** @var DateTime */
	protected $createTime;
	/** @var int */
	protected $type;
	/** @var int */
	protected $status;
	/** @var int|null */
	protected $externalLinkId;
	/** @var string */
	protected $context;
	/** @var string|null */
	protected $service;

	/**
	 * Gets the fully qualified name of table class which belongs to current model.
	 * @return string
	 */
	public static function getTableClassName()
	{
		return DocumentSessionTable::class;
	}

	public static function add(array $data, ErrorCollection $errorCollection)
	{
		if (!array_key_exists('EXTERNAL_LINK_ID', $data))
		{
			$data['EXTERNAL_LINK_ID'] = self::extractExternalLinkId($data['CONTEXT'] ?? null);
		}

		self::trackFirstEditForLimitedEdit($data);

		return parent::add($data, $errorCollection);
	}

	protected function update(array $data)
	{
		self::trackFirstEditForLimitedEdit($data);

		return parent::update($data);
	}

	private static function trackFirstEditForLimitedEdit(array $modelData): void
	{
		$type = $modelData['TYPE'] ?? null;
		if ($type === self::TYPE_EDIT)
		{
			$bitrix24Scenario = new Bitrix24Scenario();
			$bitrix24Scenario->trackFirstEditForLimitedEdit();
		}
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

	public function setUserId(int $userId): self
	{
		$this->userId = $userId;
		$this->resetReferenceValue(self::REF_USER);

		return $this;
	}

	/**
	 * @return int
	 */
	public function getUserId(): int
	{
		return $this->userId;
	}

	public function belongsToUser(int $userId): bool
	{
		return $userId === $this->getUserId();
	}

	/**
	 * @return int
	 */
	public function getOwnerId(): int
	{
		return $this->ownerId;
	}

	/**
	 * @return bool
	 */
	public function isExclusive(): bool
	{
		return $this->isExclusive;
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

	public function isOutdatedByFileContent(): bool
	{
		// When the session recorded the file's content-version at creation time, compare
		// by that monotonic version instead of the second-granular SYNC_UPDATE_TIME.
		// `GLOBAL_CONTENT_VERSION` is bumped on every `uploadVersion`, so a strict `>`
		// here is race-free (no "created in the same second as the save" blind spot that
		// the timestamp comparison below suffers from). The snapshot is written only by
		// the vibeoffice session manager; OnlyOffice sessions never carry it and fall
		// through to the unchanged timestamp logic — keeping OnlyOffice behaviour intact.
		$contentVersionSnapshot = $this->getContentVersionSnapshot();
		if ($contentVersionSnapshot !== null)
		{
			$file = $this->getObject();
			if (!$file)
			{
				return false;
			}

			return (int)$file->getGlobalContentVersion() > $contentVersionSnapshot;
		}

		$syncUpdateTime = $this->getObject()->getSyncUpdateTime();
		if (!$syncUpdateTime)
		{
			return false;
		}

		return ($syncUpdateTime->getTimestamp() - $this->getCreateTime()->getTimestamp()) > 0;
	}

	/**
	 * The file `GLOBAL_CONTENT_VERSION` captured when the session was created, if any.
	 *
	 * Neutral reader over the session CONTEXT: it is written only by the vibeoffice
	 * session manager (under its reserved `vo` key); for sessions that never stored it
	 * (e.g. OnlyOffice/Flipchart) this returns null and the caller keeps the legacy
	 * timestamp-based staleness check.
	 */
	public function getContentVersionSnapshot(): ?int
	{
		$context = self::decodeRawContext($this->getContextRaw());

		$vo = $context['vo'] ?? null;
		if (!is_array($vo))
		{
			return null;
		}

		$version = $vo['contentVersion'] ?? null;

		return is_numeric($version) ? (int)$version : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decodeRawContext(?string $raw): array
	{
		if ($raw === null || $raw === '')
		{
			return [];
		}

		try
		{
			$decoded = \Bitrix\Main\Web\Json::decode($raw);
		}
		catch (\Throwable)
		{
			return [];
		}

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @return int
	 */
	public function getType(): int
	{
		return $this->type;
	}

	public function createInfo(): DocumentInfo
	{
		$documentInfo = $this->getInfo();
		if ($documentInfo)
		{
			return $documentInfo;
		}

		$result = DocumentSessionTable::tryToAddInfo([
			'EXTERNAL_HASH' => $this->getExternalHash(),
			'OBJECT_ID' => $this->getObjectId(),
			'VERSION_ID'    => $this->getVersionId(),
			'OWNER_ID'  => $this->getOwnerId(),
		]);

		if (!$result)
		{
			throw new ObjectNotFoundException("Could not find or creat info for {$this->getExternalHash()}");
		}

		return DocumentInfo::buildFromResult($result);
	}

	/**
	 * @return int
	 */
	public function getStatus(): int
	{
		return $this->status;
	}

	public function getContextRaw(): ?string
	{
		return $this->context;
	}

	public function getExternalLinkId(): ?int
	{
		return $this->externalLinkId;
	}

	public function getServiceRaw(): ?string
	{
		return $this->service;
	}

	public function getService(): ?DocumentService
	{
		return DocumentService::tryFrom($this->service ?? '');
	}

	public function getContext(): ?DocumentSessionContext
	{
		return DocumentSessionContext::buildFromJson($this->getContextRaw());
	}

	public function matchesExternalContext(DocumentSessionContext $requestedContext): bool
	{
		$requestedExternalLinkId = $requestedContext->getExternalLinkId();
		if ($requestedExternalLinkId === null)
		{
			return true;
		}

		return $this->getExternalSessionContext()?->getExternalLinkId() === $requestedExternalLinkId;
	}

	public function isView(): bool
	{
		return $this->getType() === self::TYPE_VIEW;
	}

	public function isEdit(): bool
	{
		return $this->getType() === self::TYPE_EDIT;
	}

	public function isActive(): bool
	{
		return $this->getStatus() === self::STATUS_ACTIVE;
	}

	public function isBroken(): bool
	{
		return $this->getStatus() === self::STATUS_BROKEN;
	}

	public function isNonActive(): bool
	{
		return $this->getStatus() === self::STATUS_NON_ACTIVE;
	}

	public function setAsBroken(): bool
	{
		return $this->update([
			'STATUS' => self::STATUS_BROKEN,
		]);
	}

	public function setAsActive(): bool
	{
		return $this->update([
			'STATUS' => self::STATUS_ACTIVE,
		]);
	}

	public function setAsNonActive(): bool
	{
		return $this->update([
			'STATUS' => self::STATUS_NON_ACTIVE,
		]);
	}

	public function forkForUser(int $userId, ?DocumentSessionContext $context = null): ?self
	{
		return self::add([
			'EXTERNAL_HASH' => $this->getExternalHash(),
			'OBJECT_ID' => $this->getObjectId(),
			'VERSION_ID' => $this->getVersionId(),
			'USER_ID' => $userId,
			'OWNER_ID' => $this->getOwnerId(),
			'IS_EXCLUSIVE' => $this->isExclusive(),
			'TYPE' => $this->getType(),
			'CONTEXT' => $context? $context->toJson() : $this->getContextRaw(),
			'SERVICE' => $this->getServiceRaw(),
		], $this->errorCollection);
	}

	public function cloneWithNewHash(int $userId, ?DocumentSessionContext $context = null): ?self
	{
		return self::add([
			'OBJECT_ID' => $this->getObjectId(),
			'VERSION_ID' => $this->getVersionId(),
			'USER_ID' => $userId,
			'OWNER_ID' => $this->getOwnerId(),
			'IS_EXCLUSIVE' => $this->isExclusive(),
			'TYPE' => $this->getType(),
			'CONTEXT' => $context? $context->toJson() : $this->getContextRaw(),
			'SERVICE' => $this->getServiceRaw(),
		], $this->errorCollection);
	}

	public function createEditSession(): ?self
	{
		$currentEditSessionFilter = [
			'OBJECT_ID' => $this->getObjectId(),
			'VERSION_ID' => $this->getVersionId(),
			'TYPE' => self::TYPE_EDIT,
			'STATUS' => self::STATUS_ACTIVE,
		];
		if ($this->getServiceRaw() !== null)
		{
			$currentEditSessionFilter['SERVICE'] = $this->getServiceRaw();
		}

		$externalContext = $this->getExternalSessionContext();
		$currentEditSession = $externalContext === null
			? self::load($currentEditSessionFilter)
			: $this->findEditSessionByExternalContext($currentEditSessionFilter, $externalContext);

		if ($currentEditSession && $currentEditSession->belongsToUser($this->getUserId()))
		{
			return $currentEditSession;
		}

		if ($currentEditSession)
		{
			return $currentEditSession->forkForUser($this->getUserId(), $this->getContext());
		}

		if ($this->isSingleUsageOfExternalHash())
		{
			return $this->transformToEdit()? $this : null;
		}

		return self::add([
			'OBJECT_ID' => $this->getObjectId(),
			'USER_ID' => $this->getUserId(),
			'OWNER_ID' => $this->getUserId(),
			'IS_EXCLUSIVE' => $this->isExclusive(),
			'VERSION_ID' => $this->getVersionId(),
			'TYPE' => self::TYPE_EDIT,
			'CONTEXT' => $this->getContextRaw(),
			'SERVICE' => $this->getServiceRaw(),
		], $this->errorCollection);
	}

	private function findEditSessionByExternalContext(
		array $filter,
		DocumentSessionContext $context,
	): ?self
	{
		$filter['=EXTERNAL_LINK_ID'] = $context->getExternalLinkId();
		$sessions = self::getModelList([
			'select' => ['*'],
			'filter' => $filter,
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		]);

		return array_shift($sessions);
	}

	private static function extractExternalLinkId(mixed $context): ?int
	{
		if (!is_string($context) || $context === '')
		{
			return null;
		}

		try
		{
			return DocumentSessionContext::buildFromJson($context)?->getExternalLinkId();
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function getExternalSessionContext(): ?DocumentSessionContext
	{
		try
		{
			$context = $this->getContext();
		}
		catch (\Throwable)
		{
			return null;
		}

		return $context?->getExternalLinkId() === null ? null : $context;
	}

	/**
	 * Set and store `view` type.
	 *
	 * @return bool
	 * @throws ArgumentException
	 * @throws NotImplementedException
	 */
	public function transformToView(): bool
	{
		return $this->update([
			'TYPE' => self::TYPE_VIEW,
		]);
	}

	public function transformToEdit(): bool
	{
		return $this->update([
			'TYPE' => self::TYPE_EDIT,
		]);
	}

	public function canTransformToEdit(SecurityContext $securityContext): bool
	{
		return $this->canEdit($securityContext);
	}

	public function canEdit(SecurityContext $securityContext): bool
	{
		$context = $this->getContext();
		if (!$context)
		{
			return $securityContext->canUpdate($this->getObjectId());
		}

		if ($context->getAttachedObject() && $context->getAttachedObject()->canUpdate($securityContext->getUserId()))
		{
			return true;
		}

		if ($context->getExternalLink() && $context->getExternalLink()->allowEdit())
		{
			return true;
		}

		return $securityContext->canUpdate($this->getObjectId());
	}

	public function canUserEdit(CurrentUser $user): bool
	{
		$securityContext = $this->getSecurityContext($user);
		if (!$securityContext)
		{
			return false;
		}

		return $this->canEdit($securityContext);
	}

	public function canTransformUserToEdit(CurrentUser $user): bool
	{
		$securityContext = $this->getSecurityContext($user);
		if (!$securityContext)
		{
			return false;
		}

		return $this->canTransformToEdit($securityContext);
	}

	public function canRename(SecurityContext $securityContext): bool
	{
		return $securityContext->canRename($this->getObjectId());
	}

	public function canUserRename(CurrentUser $user): bool
	{
		$securityContext = $this->getSecurityContext($user);
		if (!$securityContext)
		{
			return false;
		}

		return $this->canRename($securityContext);
	}

	protected function getSecurityContext(CurrentUser $user): ?SecurityContext
	{
		$file = $this->getObject();
		if (!$file)
		{
			return null;
		}

		$storage = $file->getStorage();
		if (!$storage)
		{
			return null;
		}

		return $storage->getSecurityContext($user);
	}

	public function canUserShare(CurrentUser $user): bool
	{
		$securityContext = $this->getSecurityContext($user);
		if (!$securityContext)
		{
			return false;
		}

		return $this->canShare($securityContext);
	}

	public function canShare(SecurityContext $securityContext): bool
	{
		if ($securityContext->canShare($this->getObjectId()))
		{
			return true;
		}

		return false;
	}

	public function canUserChangeRights(CurrentUser $user): bool
	{
		$securityContext = $this->getSecurityContext($user);
		if (!$securityContext)
		{
			return false;
		}

		return $this->canChangeRights($securityContext);
	}

	public function canChangeRights(SecurityContext $securityContext): bool
	{
		if ($securityContext->canChangeRights($this->getObjectId()))
		{
			return true;
		}

		return false;
	}

	public function canUserRead(CurrentUser $user): bool
	{
		$securityContext = $this->getSecurityContext($user);
		if (!$securityContext)
		{
			return false;
		}

		return $this->canRead($securityContext);
	}

	public function canRead(SecurityContext $securityContext): bool
	{
		$context = $this->getContext();
		if (!$context)
		{
			return $securityContext->canRead($this->getObjectId());
		}

		if ($context->getAttachedObject() && $context->getAttachedObject()->canRead($securityContext->getUserId()))
		{
			return true;
		}

		return $securityContext->canRead($this->getObjectId());
	}

	protected function isSingleUsageOfExternalHash(): bool
	{
		return DocumentSessionTable::getCount([
			'=EXTERNAL_HASH' => $this->getExternalHash(),
			'=OBJECT_ID' => $this->getObjectId(),
		]) <= 1;
	}

	public function delete(): bool
	{
		return $this->deleteInternal();
	}

	public function countActiveSessions(): int
	{
		$countActiveSessions = DocumentSessionTable::getCount([
			'=EXTERNAL_HASH' => $this->getExternalHash(),
			'=STATUS' => DocumentSessionTable::STATUS_ACTIVE,
		]);

		return $countActiveSessions;
	}

	public static function getMapAttributes()
	{
		return [
			'ID' => 'id',
			'OBJECT_ID' => 'objectId',
			'VERSION_ID' => 'versionId',
			'USER_ID' => 'userId',
			'OWNER_ID' => 'ownerId',
			'IS_EXCLUSIVE' => 'isExclusive',
			'EXTERNAL_HASH' => 'externalHash',
			'CREATE_TIME' => 'createTime',
			'TYPE' => 'type',
			'STATUS' => 'status',
			'EXTERNAL_LINK_ID' => 'externalLinkId',
			'CONTEXT' => 'context',
			'SERVICE' => 'service',
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
			self::REF_USER => [
				'class' => User::class,
				'select' => $fields,
				'load' => function(self $documentSession){
					return User::loadById($documentSession->getUserId());
				},
			],
			self::REF_OWNER => [
				'class' => User::class,
				'select' => $fields,
				'load' => function(self $documentSession){
					return User::loadById($documentSession->getOwnerId());
				},
			],
			self::REF_OBJECT => [
				'class' => File::class,
				'load' => function(self $documentSession){
					return File::loadById($documentSession->getObjectId());
				},
			],
			self::REF_VERSION => [
				'class' => Version::class,
				'load' => function(self $documentSession){
					return Version::loadById($documentSession->getVersionId());
				},
			],
			self::REF_INFO => [
				'class' => DocumentInfo::class,
				'load' => function(self $documentSession){
					return DocumentInfo::loadById($documentSession->getExternalHash());
				},
			],
		];
	}
}
