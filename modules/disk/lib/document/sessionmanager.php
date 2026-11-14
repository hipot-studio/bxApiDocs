<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionContext;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\Restrictions\EditingRestrictionResolver;
use Bitrix\Disk\Internals\Error\Error as InternalDiskError;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Internals\Error\IErrorable;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\NotImplementedException;

abstract class SessionManager implements IErrorable
{
	protected const LOCK_LIMIT = 15;

	/** @var Version */
	protected $version;
	/** @var File */
	protected $file;
	/** @var AttachedObject */
	protected $attachedObject;
	/** @var int */
	protected $userId;
	/** @var int */
	protected $sessionType;
	/** @var DocumentSessionContext */
	protected $sessionContext;
	protected DocumentService|null $service;
	/** @var  ErrorCollection */
	protected $errorCollection;
	private string|null $externalHash;
	private ?bool $editingRestricted = null;

	public function __construct()
	{
		$this->errorCollection = new ErrorCollection();
	}

	public function lock(): bool
	{
		return Application::getConnection()->lock($this->getLockKey(), self::LOCK_LIMIT);
	}

	public function unlock(): void
	{
		Application::getConnection()->unlock($this->getLockKey());
	}

	protected function getLockKey(): string
	{
		$filter = $this->buildFilter();
		$keyData = [
			'TYPE' => $filter['TYPE'],
			'VERSION_ID' => $filter['VERSION_ID'] ?? 0,
			'OBJECT_ID' => $filter['OBJECT_ID'] ?? 0,
		];
		$externalLinkId = $this->getExternalLinkId();
		if ($externalLinkId !== null)
		{
			$keyData['EXTERNAL_LINK_ID'] = $externalLinkId;
		}

		return implode('|', array_values($keyData));
	}

	public function setSessionType(int $sessionType): self
	{
		$this->sessionType = $sessionType;

		return $this;
	}

	/**
	 * The type the session is really opened with.
	 *
	 * A request to edit a document the portal keeps closed comes back as a view: the document
	 * opens, but no edit session appears behind it. Both the lookup and the creation of the session
	 * go through here, so such a document reuses and leaves behind view sessions only.
	 *
	 * Which of the reasons closed it - the restricted format or the exhausted personal quota - is
	 * of no interest to a session that has no interface of its own: the type is all that is written
	 * down here, and the words about it are shown by the editor.
	 */
	protected function getEffectiveSessionType(): ?int
	{
		if ($this->sessionType !== DocumentSession::TYPE_EDIT)
		{
			return $this->sessionType;
		}

		$this->editingRestricted ??= (new EditingRestrictionResolver())
			->isRestricted($this->resolveFileName(), $this->resolveFile())
		;

		return $this->editingRestricted ? DocumentSession::TYPE_VIEW : DocumentSession::TYPE_EDIT;
	}

	/**
	 * Name of the document the session is being opened for, as far as the manager knows it. A
	 * session addressed by its hash alone carries no document to name, and the format policy has
	 * nothing to judge.
	 */
	private function resolveFileName(): ?string
	{
		if ($this->version)
		{
			return $this->version->getName();
		}

		return $this->resolveFile()?->getName();
	}

	/**
	 * The file the session is being opened over, which the quota is asked about: the document is
	 * written back into it, and the disk it lies in is the one that has to have room left.
	 *
	 * A session opened for a version names it as its own document, but the version belongs to a
	 * file all the same, and it is that file the editing would reach. The order is the one of
	 * {@see DocumentSource::getFileFromSource()}, so the seam judges the document the source of the
	 * session resolved - and the caller has usually resolved it already, which is why the version
	 * hands its file over without a second lookup.
	 */
	private function resolveFile(): ?File
	{
		return $this->file ?: $this->version?->getObject() ?: $this->attachedObject?->getFile();
	}

	public function setSessionContext(DocumentSessionContext $sessionContext): self
	{
		$this->sessionContext = $sessionContext;

		return $this;
	}

	public function setUserId(int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function setVersion(?Version $version): self
	{
		$this->version = $version;

		return $this;
	}

	public function setFile(?File $file): self
	{
		$this->file = $file;

		return $this;
	}

	public function setExternalHash(string $hash): self
	{
		$this->externalHash = $hash;

		return $this;
	}

	public function setAttachedObject(?AttachedObject $attachedObject): self
	{
		$this->attachedObject = $attachedObject;

		return $this;
	}

	public function setService(DocumentService $service): self
	{
		$this->service = $service;

		return $this;
	}

	public function getService(): ?DocumentService
	{
		return $this->service ?? null;
	}

	public function findOrCreateSession($exactUser = false): ?DocumentSession
	{
		$session = $this->findSession($exactUser) ?: $this->addSession();
		if (!$session)
		{
			return null;
		}

		if ($session->isView() && $session->isOutdatedByFileContent())
		{
			$session = $this->addSession();
			if (!$session)
			{
				return null;
			}
		}

		if (!$session->belongsToUser($this->getUserId()))
		{
			$fork = $session->forkForUser($this->getUserId(), $this->sessionContext);
			if (!$fork)
			{
				$this->errorCollection->add($session->getErrors());
			}

			return $fork;
		}

		if ($session->isNonActive())
		{
			return $session->cloneWithNewHash($this->getUserId(), $this->sessionContext);
		}

		return $session;
	}

	public function findSession($exactUser = false): ?DocumentSession
	{
		$filter = $this->buildFilter();

		$session = $this->getExternalLinkId() === null
			? $this->findInternalSession($filter)
			: $this->findExternalSession($filter);
		if ($session)
		{
			return $session;
		}

		if ($exactUser)
		{
			return null;
		}

		unset($filter['USER_ID']);

		return $this->getExternalLinkId() === null
			? $this->findInternalSession($filter)
			: $this->findExternalSession($filter);
	}

	private function findInternalSession(array $filter): ?DocumentSession
	{
		$models = DocumentSession::getModelList([
			'select' => ['*'],
			'filter' => $filter,
			'limit' => 1,
			'order' => ['ID' => 'DESC'],
		]);

		return array_shift($models);
	}

	private function findExternalSession(array $filter): ?DocumentSession
	{
		$filter['=EXTERNAL_LINK_ID'] = $this->getExternalLinkId();
		$models = DocumentSession::getModelList([
			'select' => ['*'],
			'filter' => $filter,
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		]);

		return array_shift($models);
	}

	private function getExternalLinkId(): ?int
	{
		return $this->sessionContext?->getExternalLinkId();
	}

	/**
	 * @throws ArgumentException
	 */
	protected function buildFields(): array
	{
		$filter = [
			'USER_ID' => $this->userId,
			'IS_EXCLUSIVE' => false,
			'STATUS' => DocumentSession::STATUS_ACTIVE,
			'VERSION_ID' => null,
			'SERVICE' => $this->service->value,
		];

		if ($this->version)
		{
			$filter['VERSION_ID'] = $this->version->getId();
			$filter['OBJECT_ID'] = $this->version->getObjectId();
		}
		elseif ($this->file)
		{
			$filter['OBJECT_ID'] = $this->file->getRealObjectId();
		}
		elseif ($this->attachedObject)
		{
			$filter['OBJECT_ID'] = $this->attachedObject->getObjectId();
			if ($this->attachedObject->isSpecificVersion())
			{
				$filter['VERSION_ID'] = $this->attachedObject->getVersionId();
			}
		}
		elseif ($this->externalHash)
		{
			$filter['EXTERNAL_HASH'] = $this->externalHash;
		}
		else
		{
			throw new ArgumentException('Neither file nor version nor attached object were installed.');
		}

		return $filter;
	}

	/**
	 * @throws ArgumentException
	 */
	protected function buildFilter(): array
	{
		$filter = $this->buildFields();

		if (array_key_exists('SERVICE', $filter))
		{
			$filter['=SERVICE'] = $filter['SERVICE'];

			unset($filter['SERVICE']);
		}

		if (array_key_exists('EXTERNAL_HASH', $filter))
		{
			$filter['=EXTERNAL_HASH'] = $filter['EXTERNAL_HASH'];

			unset($filter['EXTERNAL_HASH']);
		}

		return $filter;
	}

	/**
	 * @return DocumentSession|null
	 * @throws ArgumentException
	 * @throws NotImplementedException
	 */
	public function addSession(): ?DocumentSession
	{
		$fields = $this->buildFields();
		$fields['OWNER_ID'] = $this->userId;
		$fields['CONTEXT'] = $this->sessionContext->toJson();
		$fields['EXTERNAL_LINK_ID'] = $this->getExternalLinkId();
		$fields['SERVICE'] = $this->service->value;

		return DocumentSession::add(
			$fields,
			$this->errorCollection,
			isset($this->file)
				? $this->file->getRealObject()->getUploadCleanupMarkerPresenceHint()
				: null,
		);
	}

	/**
	 * @return InternalDiskError[]
	 */
	public function getErrors(): array
	{
		return $this->errorCollection->toArray();
	}

	/**
	 * @param string $code
	 * @return InternalDiskError[]
	 */
	public function getErrorsByCode($code): array
	{
		return $this->errorCollection->getErrorsByCode($code);
	}

	/**
	 * @param string $code
	 * @return InternalDiskError|Error|null
	 */
	public function getErrorByCode($code): InternalDiskError|Error|null
	{
		return $this->errorCollection->getErrorByCode($code);
	}
}
