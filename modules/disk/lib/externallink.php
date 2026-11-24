<?php

namespace Bitrix\Disk;

use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Document\Vibeoffice\AccessRevocation;
use Bitrix\Disk\Document\Vibeoffice\DocumentStateRepository;
use Bitrix\Disk\Document\Vibeoffice\SqlDeadlineScope;
use Bitrix\Disk\Internal\Enum\ExternalLinkScenario;
use Bitrix\Disk\Internal\Service\Audit\AuditPublicLinkEventProducer;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionOutcome;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionResult;
use Bitrix\Disk\Internal\Service\Document\OfficeEngineRecognizer;
use Bitrix\Disk\Internal\Service\Sync\Access\LinkAccessPublisher;
use Bitrix\Disk\Internal\Service\Sync\Access\PublicAccessPublisher;
use Bitrix\Disk\Internal\Service\UploadCleanup\MarkerService;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Uri;
use CBXShortUri;
use Random\Engine;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

final class ExternalLink extends Internals\Model
{
	public const TYPE_AUTO = ExternalLinkTable::TYPE_AUTO;
	public const TYPE_MANUAL = ExternalLinkTable::TYPE_MANUAL;

	public const ACCESS_RIGHT_VIEW = ExternalLinkTable::ACCESS_RIGHT_VIEW;
	public const ACCESS_RIGHT_EDIT = ExternalLinkTable::ACCESS_RIGHT_EDIT;
	public const ERROR_SETTINGS_EDIT_DENIED = 'DISK_EL_22001';

	private const SHORT_CODE_BYTES = 16;
	private const SHORT_CODE_RESERVE_ATTEMPTS = 3;
	private const IMMEDIATE_REVOKE_SECONDS = 2.0;
	private const IMMEDIATE_REVOKE_LIMIT = 10;

	/** @var int */
	protected $objectId;
	/** @var Object */
	protected $object;
	/** @var int */
	protected $versionId;
	/** @var Version */
	protected $version;
	/** @var string */
	protected $hash;
	/** @var string */
	protected $password;
	/** @var string */
	protected $salt;
	/** @var  DateTime */
	protected $deathTime;
	/** @var string */
	protected $description;
	/** @var int */
	protected $downloadCount;
	/** @var int */
	protected $accessRight;
	/** @var int */
	protected $type;
	/** @var  DateTime */
	protected $createTime;
	/** @var int */
	protected $createdBy;
	/** @var User */
	protected $createUser;
	/** @var int */
	protected $canDownloadWithReadAccess;
	/** @var bool */
	protected $canEditSettings;
	/** @var string|null */
	protected $scenario;
	/** @var string|null */
	protected $code;

	/**
	 * Gets the fully qualified name of table class which belongs to current model.
	 * @throws \Bitrix\Main\NotImplementedException
	 * @return string
	 */
	public static function getTableClassName()
	{
		return ExternalLinkTable::className();
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
	 * Returns user model, who created object.
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
	 * Returns id of user, who created object.
	 * @return int
	 */
	public function getCreatedBy()
	{
		return $this->createdBy;
	}

	/**
	 * Returns time to death object.
	 * @return DateTime
	 */
	public function getDeathTime()
	{
		return $this->deathTime;
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
	 * Returns download count.
	 * @return int
	 */
	public function getDownloadCount()
	{
		return $this->downloadCount;
	}

	/**
	 * Returns hash.
	 * @return string
	 */
	public function getHash()
	{
		return $this->hash;
	}

	/**
	 * Returns id of object which is published.
	 * @return int
	 */
	public function getObjectId()
	{
		return $this->objectId;
	}

	/**
	 * Returns id of version which is published.
	 *
	 * Not filled if not published version.
	 * @see self::isSpecificVersion()
	 * @return int
	 */
	public function getVersionId()
	{
		return $this->versionId;
	}

	/**
	 * Returns version model.
	 *
	 * Not filled if not published version.
	 * @see self::isSpecificVersion()
	 * @return Version|null
	 */
	public function getVersion()
	{
		if(!$this->versionId)
		{
			return null;
		}

		if(isset($this->version) && $this->versionId == $this->version->getId())
		{
			return $this->version;
		}
		$this->version = Version::loadById($this->versionId);

		return $this->version;
	}

	/**
	 * Returns file.
	 *
	 * @return null|File
	 */
	public function getFile()
	{
		$object = $this->getObject();
		if(!$object instanceof File)
		{
			return null;
		}

		return $object;
	}

	/**
	 * Returns folder.
	 *
	 * @return null|Folder
	 */
	public function getFolder()
	{
		$object = $this->getObject();
		if(!$object instanceof Folder)
		{
			return null;
		}

		return $object;
	}

	/**
	 * Returns object.
	 *
	 * @return null|BaseObject
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

		$this->object = BaseObject::loadById($this->objectId);

		return $this->object;
	}

	/**
	 * Returns password hash.
	 * @return string
	 */
	public function getPassword()
	{
		return $this->password;
	}

	/**
	 * Tells if external link has password.
	 * @return bool
	 */
	public function hasPassword()
	{
		return (bool)$this->password;
	}

	/**
	 * Tells if external link has time to death.
	 * @return bool
	 */
	public function hasDeathTime()
	{
		return (bool)$this->deathTime;
	}

	/**
	 * Returns salt.
	 * @return string
	 */
	public function getSalt()
	{
		return $this->salt;
	}

	/**
	 * Returns type of external link.
	 * @see ExternalLinkTable::getListOfTypeValues().
	 * @return int
	 */
	public function getType(): int
	{
		return $this->type;
	}

	/**
	 * Returns scenario of external link, null for a regular manual link.
	 */
	public function getScenario(): ?ExternalLinkScenario
	{
		if ($this->scenario === null)
		{
			return null;
		}

		return ExternalLinkScenario::tryFrom($this->scenario);
	}

	/**
	 * Tells whether a short code already issued to the link still opens it: a manual link outside any special
	 * scenario. Unlike the issue, the opening does not depend on the unified link support of the portal.
	 */
	public function canBeOpenedByShortCode(): bool
	{
		return (int)$this->type === self::TYPE_MANUAL && $this->scenario === null;
	}

	/**
	 * Tells whether the link may have a short public code: a manual link of a file under unified links
	 * outside any special scenario. Folders, automatic and scenario links keep their legacy addresses.
	 */
	public function canHaveShortCode(): bool
	{
		if (!$this->canBeOpenedByShortCode())
		{
			return false;
		}

		$object = $this->getObject();

		return $object instanceof File && $object->supportsUnifiedLink();
	}

	/**
	 * Returns short public code of the link, null until it is reserved by {@see ExternalLink::ensureShortCode()}.
	 */
	public function getShortCode(): ?string
	{
		return $this->code;
	}

	/**
	 * Returns short public code of the link and reserves a random one on the first call.
	 * The code never changes once stored: a concurrent reservation of the same link returns the code of the winner.
	 * @param Engine|null $engine Source of the random bytes, the secure one by default.
	 * @return string
	 * @throws SystemException When the code is not reserved; there is no fallback to a weaker generator.
	 */
	public function ensureShortCode(?Engine $engine = null): string
	{
		if ($this->code === null)
		{
			$this->code = $this->reserveShortCode(new Randomizer($engine ?? new Secure()));
		}

		return $this->code;
	}

	/**
	 * Returns access right (view, edit).
	 * @return int
	 */
	public function getAccessRight(): int
	{
		return (int)$this->accessRight;
	}

	public function getCanDownloadWithReadAccess(): int
	{
		return (int)$this->canDownloadWithReadAccess;
	}

	public function isCanDownloadWithReadAccess(): bool
	{
		return (bool)$this->getCanDownloadWithReadAccess();
	}

	public function changeCanDownloadWithReadAccess($val)
	{
		return $this->changeAccessSettings(
			$this->getAccessRight(),
			(bool)$val,
		);
	}

	public function getCanEditSettings(): bool
	{
		return (bool)$this->canEditSettings;
	}

	public function canEditSettings(): bool
	{
		return $this->getCanEditSettings();
	}

	public function allowEdit(): bool
	{
		return $this->getAccessRight() === self::ACCESS_RIGHT_EDIT;
	}

	public function allowView(): bool
	{
		return true;
	}

	public function availableEdit(): bool
	{
		$object = $this->getObject();
		if (!($object instanceof File))
		{
			return false;
		}

		// Boards are edited by their own editor, so the default document viewer does not restrict them.
		if ((int)$object->getTypeFile() === TypeFile::BOARD)
		{
			return true;
		}

		if (!DocumentHandler::isEditable($object->getExtension()))
		{
			return false;
		}

		$documentHandlersManager = Driver::getInstance()->getDocumentHandlersManager();
		$documentHandler = $documentHandlersManager->getDefaultHandlerForView();

		return OfficeEngineRecognizer::isOfficeEngine($documentHandler);
	}

	/**
	 * Tells if the external link has type AUTO.
	 * @return bool
	 */
	public function isAutomatic()
	{
		return $this->type == self::TYPE_AUTO;
	}

	/**
	 * Tells if external link serves mail attachments.
	 */
	public function isMailAttachmentLink(): bool
	{
		return $this->getScenario() === ExternalLinkScenario::MailAttachments;
	}

	/**
	 * Tells if external link has specific version.
	 * @return bool
	 */
	public function isSpecificVersion()
	{
		return (bool)$this->versionId;
	}

	/**
	 * Tells if external link belongs to image.
	 * @return bool
	 */
	public function isImage()
	{
		$file = $this->getFile();
		if(!$file)
		{
			return false;
		}

		$fileData = $file->getFile();
		if(!$fileData || empty($fileData['CONTENT_TYPE']))
		{
			return false;
		}
		return mb_strpos($fileData['CONTENT_TYPE'], 'image/') === 0;
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
			'OBJECT_ID' => 'objectId',
			'OBJECT' => 'object',
			'VERSION_ID' => 'versionId',
			'VERSION' => 'version',
			'HASH' => 'hash',
			'PASSWORD' => 'password',
			'SALT' => 'salt',
			'DEATH_TIME' => 'deathTime',
			'DESCRIPTION' => 'description',
			'DOWNLOAD_COUNT' => 'downloadCount',
			'ACCESS_RIGHT' => 'accessRight',
			'TYPE' => 'type',
			'CREATE_TIME' => 'createTime',
			'CREATED_BY' => 'createdBy',
			'CREATE_USER' => 'createUser',
			'CAN_DOWNLOAD_WITH_READ_ACCESS' => 'canDownloadWithReadAccess',
			'CAN_EDIT_SETTINGS' => 'canEditSettings',
			'SCENARIO' => 'scenario',
			'CODE' => 'code',
		);
	}

	/**
	 * Returns the list attributes which is connected with another models.
	 * @return array
	 */
	public static function getMapReferenceAttributes()
	{
		return array(
			'CREATE_USER' => array(
				'class' => User::className(),
				'select' => User::getFieldsForSelect(),
			),
			'OBJECT' => array(
				'orm_alias' => 'OBJECT',
				'class' => BaseObject::className(),
			),
			'FILE' => array(
				'orm_alias' => 'OBJECT',
				'class' => BaseObject::className(),
			),
			'VERSION' => Version::className(),
		);
	}

	/**
	 * Adds row to entity table, fills error collection and build model.
	 * @param array           $data Data.
	 * @param ErrorCollection $errorCollection Error collection.
	 * @return \Bitrix\Disk\Internals\Model|static|null
	 * @throws \Bitrix\Main\NotImplementedException
	 * @internal
	 */
	public static function add(
		array $data,
		ErrorCollection $errorCollection,
		?bool $hasUploadCleanupMarker = null,
	)
	{
		static::checkRequiredInputParams($data, array(
			'OBJECT_ID',
		));

		if (!(new MarkerService())->revokeBeforeUse((int)$data['OBJECT_ID'], $hasUploadCleanupMarker))
		{
			$errorCollection->addOne(new Error(MarkerService::ERROR_FILE_IS_BEING_REMOVED));

			return null;
		}

		if(!empty($data['PASSWORD']))
		{
			list($data['PASSWORD'], $data['SALT']) = ExternalLink::generatePasswordAndSalt($data['PASSWORD']);
		}

		$data['HASH'] = md5(uniqid($data['OBJECT_ID'], true) . \CMain::getServerUniqID());

		return parent::add($data, $errorCollection);
	}

	/**
	 * Generates password hash and salt hash by user-input.
	 * @param string $password Password (plain).
	 * @return array
	 */
	protected static function generatePasswordAndSalt($password)
	{
		$salt = md5(uniqid());
		return array(
			static::hashPassword($password, $salt),
			$salt
		);
	}

	/**
	 * Hashes password by salt.
	 * @param string $password Password (plain).
	 * @param string $salt Salt.
	 * @return string
	 */
	protected static function hashPassword($password, $salt)
	{
		return md5($password . $salt);
	}

	/**
	 * Tells if input password is correct for external link.
	 * @param string $password Password (plain).
	 * @return bool
	 */
	public function checkPassword($password)
	{
		return $this->password === $this->hashPassword($password, $this->salt);
	}

	/**
	 * Tells if external link is expired.
	 * @return bool
	 */
	public function isExpired()
	{
		$now = new DateTime;
		return $this->deathTime && $now->getTimestamp() > $this->deathTime->getTimestamp();
	}

	/**
	 * Increments download count.
	 * @return bool
	 */
	public function incrementDownloadCount()
	{
		$this->errorCollection->clear();
		$success = $this->update(array(
			'DOWNLOAD_COUNT' => new SqlExpression('?# + 1', 'DOWNLOAD_COUNT'),
		));

		if($success)
		{
			$this->downloadCount++;
		}

		return $success;
	}

	public function revokeDeathTime()
	{
		if (!$this->canChangeSettings())
		{
			return false;
		}

		return $this->runAuditCoveredMutation(function (): bool {
			$deathTimeBefore = $this->getDeathTime();
			$oldTtl = $deathTimeBefore?->getTimestamp();
			$updated = $this->update(array(
				'DEATH_TIME' => null,
			));

			if ($updated && $oldTtl !== null)
			{
				$auditResult = (new AuditPublicLinkEventProducer())->publishTtlChanged(
					$this,
					$oldTtl,
					null,
					$this->getCurrentUserId(),
				);
				if (!$this->applyAuditResult($auditResult))
				{
					return false;
				}

				$this->publishPublicAccessUpdated();
			}

			if ($updated)
			{
				$this->publishLifetimeChange($deathTimeBefore, null);
			}

			return $updated;
		}, fn(): bool => $this->getDeathTime() !== null);
	}

	public function revokePassword()
	{
		if (!$this->canChangeSettings())
		{
			return false;
		}

		return $this->runAuditCoveredMutation(function (): bool {
			$hadPassword = $this->hasPassword();
			$updated = $this->update(array(
				'PASSWORD' => null,
				'SALT' => null,
			));

			if ($updated && $hadPassword)
			{
				$auditResult = (new AuditPublicLinkEventProducer())->publishPasswordDisabled(
					$this,
					$this->getCurrentUserId(),
				);
				if (!$this->applyAuditResult($auditResult))
				{
					return false;
				}

				$this->publishPublicAccessUpdated();
			}

			return $updated;
		});
	}

	/**
	 * Changes time to death.
	 * @param DateTime $dateTime Time to death.
	 * @return bool
	 */
	public function changeDeathTime(DateTime $dateTime)
	{
		if (!$this->canChangeSettings())
		{
			return false;
		}

		return $this->runAuditCoveredMutation(function () use ($dateTime): bool {
			$deathTimeBefore = $this->getDeathTime();
			$oldTtl = $deathTimeBefore?->getTimestamp();
			$newTtl = $dateTime->getTimestamp();
			$updated = $this->update(array(
				'DEATH_TIME' => $dateTime,
			));

			if ($updated && $oldTtl !== $newTtl)
			{
				$auditResult = (new AuditPublicLinkEventProducer())->publishTtlChanged(
					$this,
					$oldTtl,
					$newTtl,
					$this->getCurrentUserId(),
				);
				if (!$this->applyAuditResult($auditResult))
				{
					return false;
				}

				$this->publishPublicAccessUpdated();
			}

			if ($updated)
			{
				$this->publishLifetimeChange($deathTimeBefore, $dateTime);
			}

			return $updated;
		}, fn(): bool => $this->getDeathTime() === null
			|| $dateTime->getTimestamp() < $this->getDeathTime()->getTimestamp());
	}

	/**
	 * A lifetime that ran out switches the link off without deleting it, and one that is taken away
	 * lights it again - both move the boundary for whoever held the object by this link alone.
	 */
	private function publishLifetimeChange(?DateTime $deathTimeBefore, ?DateTime $deathTimeAfter): void
	{
		(new LinkAccessPublisher())->publishExternalLinkLifetimeChanged(
			(int)$this->getObjectId(),
			$deathTimeBefore,
			$deathTimeAfter,
		);
	}

	/**
	 * Only the mode, the lifetime and the protection by a password are a part of the public access of the
	 * object, so the callers publish only a change of one of them.
	 */
	private function publishPublicAccessUpdated(): void
	{
		(new PublicAccessPublisher())->publishUpdated($this, $this->getCurrentUserId());
	}

	/**
	 * Changes password.
	 * @param string $newPassword Password (plain).
	 * @return bool
	 */
	public function changePassword($newPassword)
	{
		if (!$this->canChangeSettings())
		{
			return false;
		}

		return $this->runAuditCoveredMutation(function () use ($newPassword): bool {
			$hadPassword = $this->hasPassword();
			$data = array();
			list($data['PASSWORD'], $data['SALT']) = ExternalLink::generatePasswordAndSalt($newPassword);

			$updated = $this->update($data);
			if ($updated && !$hadPassword)
			{
				$auditResult = (new AuditPublicLinkEventProducer())->publishPasswordEnabled(
					$this,
					$this->getCurrentUserId(),
				);
				if (!$this->applyAuditResult($auditResult))
				{
					return false;
				}

				$this->publishPublicAccessUpdated();
			}

			return $updated;
		});
	}

	public function changeAccessRight(int $right): bool
	{
		return $this->changeAccessSettings(
			$right,
			$this->isCanDownloadWithReadAccess(),
		);
	}

	public function changeAccessSettings(int $right, bool $canDownloadWithReadAccess): bool
	{
		if (!$this->canChangeSettings())
		{
			return false;
		}

		return $this->runAuditCoveredMutation(function () use ($right, $canDownloadWithReadAccess): bool {
			$oldAccessRight = $this->getAccessRight();
			$oldCanDownloadWithReadAccess = $this->isCanDownloadWithReadAccess();
			if ($oldAccessRight === $right && $oldCanDownloadWithReadAccess === $canDownloadWithReadAccess)
			{
				return true;
			}

			$updated = $this->update([
				'ACCESS_RIGHT' => $right,
				'CAN_DOWNLOAD_WITH_READ_ACCESS' => $canDownloadWithReadAccess ? 1 : 0,
			]);

			if ($updated)
			{
				$auditResult = (new AuditPublicLinkEventProducer())->publishAccessChanged(
					$this,
					$oldAccessRight,
					$right,
					$canDownloadWithReadAccess,
					$this->getCurrentUserId(),
				);
				if (!$this->applyAuditResult($auditResult))
				{
					return false;
				}

				// the right to download by a read link is not a part of the public access of the object
				if ($oldAccessRight !== $right)
				{
					$this->publishPublicAccessUpdated();
				}
			}

			return $updated;
		}, fn(): bool => $this->getAccessRight() === self::ACCESS_RIGHT_EDIT && $right !== self::ACCESS_RIGHT_EDIT);
	}

	private function canChangeSettings(): bool
	{
		if ($this->canEditSettings())
		{
			return true;
		}

		$this->errorCollection->add([
			new Error('External link settings editing is denied', self::ERROR_SETTINGS_EDIT_DENIED),
		]);

		return false;
	}

	/**
	 * Scenario is assigned on creation only and the short code by {@see ExternalLink::ensureShortCode()} only,
	 * so both are dropped from any update.
	 * @inheritDoc
	 */
	protected function update(array $data)
	{
		unset($data['SCENARIO'], $data['CODE']);

		return parent::update($data);
	}

	/**
	 * Removes expired links with type AUTO.
	 * @internal Uses in CAgent.
	 * @return string
	 */
	public static function removeExpiredWithTypeAuto()
	{
		$models = static::getModelList([
			'filter' => [
				'TYPE' => self::TYPE_AUTO,
				'IS_EXPIRED' => true,
			],
			'limit' => 100,
		]);

		foreach ($models as $model)
		{
			/** @var ExternalLink $model */
			$model->delete();
		}

		return static::className() . '::removeExpiredWithTypeAuto();';
	}

	/**
	 * Deletes external link and short uri.
	 * @return bool
	 */
	public function delete(?AuditTransaction $auditTransaction = null)
	{
		$mutation = function (): bool {
			$deleted = $this->deleteInternal();
			if ($deleted)
			{
				// the object may have left the sight of whoever held it by this link and by nothing else
				(new LinkAccessPublisher())->publishExternalLinkRevoked((int)$this->getObjectId());
			}
			if ($deleted && $this->getType() === self::TYPE_MANUAL)
			{
				$auditResult = (new AuditPublicLinkEventProducer())->publishDisabled(
					$this,
					$this->getCurrentUserId(),
				);
				if (!$this->applyAuditResult($auditResult))
				{
					return false;
				}

				(new PublicAccessPublisher())->publishDisabled($this, $this->getCurrentUserId());
			}

			return $deleted;
		};
		$afterCommit = function (): void {
			$this->deleteShortUris();
		};

		if ($auditTransaction !== null)
		{
			$deleted = $this->runAuditCoveredMutation($mutation, static fn(): bool => true);
			if ($deleted && $this->getType() === self::TYPE_MANUAL)
			{
				$auditTransaction->addAfterCommit($afterCommit);
			}

			return $deleted;
		}

		$transactionResult = (new AuditTransaction(Application::getConnection()))->execute(
			function (AuditTransaction $transaction) use ($mutation, $afterCommit): AuditTransactionOutcome {
				$deleted = $this->runAuditCoveredMutation($mutation, static fn(): bool => true);
				if (!$deleted)
				{
					return AuditTransactionOutcome::failure(false);
				}

				if ($this->getType() === self::TYPE_MANUAL)
				{
					$transaction->addAfterCommit($afterCommit);
				}

				return AuditTransactionOutcome::success(true);
			},
		);

		if ($transactionResult->isSuccess())
		{
			return true;
		}

		$this->applyAuditTransactionFailure($transactionResult);

		return false;
	}

	private function runAuditCoveredMutation(callable $callback, ?callable $revokeWhen = null): bool
	{
		return AuditTransaction::run(function (AuditTransaction $owner) use ($callback, $revokeWhen): bool {
			if ($revokeWhen === null)
			{
				return $callback();
			}
			$realObjectId = (int)($this->getObject()?->getRealObjectId() ?? $this->getObjectId());
			DocumentStateRepository::fetchForUpdate(Internals\ObjectTable::query()
				->setSelect(['ID'])->where('ID', $realObjectId));
			$source = DocumentStateRepository::fetchForUpdate(ExternalLinkTable::query()
				->setSelect(['*'])->where('ID', $this->getId()));
			if (!$source)
			{
				return false;
			}
			$this->setAttributes($source);
			$ids = [];
			if ($revokeWhen())
			{
				$marked = (new AccessRevocation())->markExternalSourceLocked(
					$realObjectId,
					(int)$this->getId(),
					$this->getObject() instanceof Folder,
				);
				if (!$this->applyAuditResult($marked))
				{
					return false;
				}
				$ids = $marked->getData()['registryIds'];
			}
			if (!$callback())
			{
				return false;
			}
			if ($ids !== [])
			{
				$owner->addAfterConfirmedCommit(static function () use ($ids): void {
					$deadline = hrtime(true) / 1e9 + self::IMMEDIATE_REVOKE_SECONDS;
					SqlDeadlineScope::run($deadline, static function () use ($ids, $deadline): \Bitrix\Main\Result {
						$revocation = new AccessRevocation();
						foreach (array_slice($ids, 0, self::IMMEDIATE_REVOKE_LIMIT) as $id)
						{
							if (hrtime(true) / 1e9 >= $deadline)
							{
								break;
							}
							$revocation->processBinding($id, $deadline);
						}

						return new \Bitrix\Main\Result();
					});
				});
			}

			return true;
		});
	}

	private function applyAuditResult(\Bitrix\Main\Result $result): bool
	{
		if ($result->isSuccess())
		{
			return true;
		}

		$this->errorCollection->add($result->getErrors());

		return false;
	}

	private function getCurrentUserId(): ?int
	{
		global $USER;

		if ($USER instanceof \CUser && $USER->isAuthorized())
		{
			return (int)$USER->getId();
		}

		return null;
	}

	private function applyAuditTransactionFailure(AuditTransactionResult $result): void
	{
		$exception = $result->getExceptions()[0] ?? null;
		if ($exception !== null)
		{
			$this->errorCollection[] = new Error($exception->getMessage());

			return;
		}

		if (!$result->isRollbackConfirmed())
		{
			$this->errorCollection[] = new Error('External link transaction failed.');
		}
	}

	private function deleteShortUris(): void
	{
		$urlManager = Driver::getInstance()->getUrlManager();

		$this->deleteShortUri($urlManager->getUrlExternalLink([
			'hash' => $this->getHash(),
			'action' => 'default',
		]));
		$this->deleteShortUri($urlManager->getUrlExternalLink([
			'hash' => $this->getHash(),
			'action' => 'default',
		], true));
	}

	private function deleteShortUri($uri)
	{
		$uriCrc32 = CBXShortUri::crc32($uri);
		$query = CBXShortUri::getList(array(), array("URI_CRC" => $uriCrc32));
		if($result = $query->fetch())
		{
			CBXShortUri::delete($result['ID']);
		}
	}

	private function reserveShortCode(Randomizer $randomizer): string
	{
		$connection = Application::getConnection();
		$tableName = ExternalLinkTable::getTableName();

		for ($attempt = 0; $attempt < self::SHORT_CODE_RESERVE_ATTEMPTS; $attempt++)
		{
			$code = $this->generateShortCode($randomizer);

			// A nested level is a savepoint, so a collision leaves the transaction of the caller usable.
			$connection->startTransaction();
			try
			{
				$connection->queryExecute((new SqlExpression(
					'UPDATE ?# SET CODE = ?s WHERE ID = ?i AND CODE IS NULL',
					$tableName,
					$code,
					$this->getId(),
				))->compile());
				$reservedCode = $connection->getAffectedRowsCount() === 1
					? $code
					: $this->readReservedShortCode($connection)
				;
				$connection->commitTransaction();
			}
			catch (DuplicateEntryException)
			{
				self::rollbackOwnTransactionLevel($connection);

				continue;
			}
			catch (\Throwable $exception)
			{
				self::rollbackOwnTransactionLevel($connection);

				throw $exception;
			}

			if ($reservedCode === null)
			{
				throw new SystemException('External link is not found to reserve its short code.');
			}

			return $reservedCode;
		}

		throw new SystemException('Short code of external link collides on every attempt.');
	}

	/**
	 * A competing reservation won: its code is read with a locking read, which sees the committed row
	 * instead of the snapshot of an outer transaction.
	 */
	private function readReservedShortCode(Connection $connection): ?string
	{
		$row = $connection->query((new SqlExpression(
			'SELECT CODE FROM ?# WHERE ID = ?i FOR UPDATE',
			ExternalLinkTable::getTableName(),
			$this->getId(),
		))->compile())->fetch();

		return $row['CODE'] ?? null;
	}

	private function generateShortCode(Randomizer $randomizer): string
	{
		try
		{
			return bin2hex($randomizer->getBytes(self::SHORT_CODE_BYTES));
		}
		catch (RandomException $exception)
		{
			throw new SystemException('Random source is not available to generate short code.', 0, '', 0, $exception);
		}
	}

	/** The core undoes a nested level on its own and only then reports it by throwing. */
	private static function rollbackOwnTransactionLevel(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException $exception)
		{
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
			{
				throw $exception;
			}
		}
	}

	public function generateUrl(): Uri
	{
		$driver = Driver::getInstance();

		return new Uri($driver->getUrlManager()->getCopyablePublicExternalLinkForConsumer(
			$this,
			UrlManager::PUBLIC_LINK_CONSUMER_SIGNED_COPY,
		));
	}
}
