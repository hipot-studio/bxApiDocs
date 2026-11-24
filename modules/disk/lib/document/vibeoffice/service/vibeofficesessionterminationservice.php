<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Service;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\SessionTerminationService;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientFactory;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientInterface;
use Bitrix\Disk\Document\Vibeoffice\HostDocumentKey;
use Bitrix\Disk\Document\Vibeoffice\AccessRevocation;
use Bitrix\Disk\Document\Vibeoffice\DocumentStateRepository;
use Bitrix\Disk\Document\Vibeoffice\SessionRegistry;
use Bitrix\Disk\Document\Vibeoffice\SharedEditingSchema;
use Bitrix\Disk\Document\Vibeoffice\SharedEditingAccess;
use Bitrix\Disk\Document\Models\DocumentSessionContext;
use Bitrix\Disk\Document\Vibeoffice\SharedSessionScope;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\FileTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Result;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Document\Vibeoffice\UnifiedLinkSourceRevision;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\UserTable;
use Psr\Container\NotFoundExceptionInterface;

/** Revokes persisted access contexts while retaining shared editing content carriers. */
class VibeofficeSessionTerminationService implements SessionTerminationService
{
	private readonly ProtocolClientInterface $protocolClient;

	/**
	 * @throws ConfigurationException when the vibeoffice client cannot be assembled
	 * @throws ObjectNotFoundException
	 * @throws NotFoundExceptionInterface
	 */
	public function __construct(
		private readonly BaseObject $object,
	)
	{
		$this->protocolClient = ProtocolClientFactory::create();
	}

	public function terminateSessionsWithInsufficientRights(): void
	{
		$sessionsToTerminate = $this->getSessionsToTerminate();
		if (empty($sessionsToTerminate))
		{
			return;
		}

		$legacy = [];
		$repository = new DocumentStateRepository();
		foreach ($sessionsToTerminate as $session)
		{
			$key = HostDocumentKey::resolve($session);
			$state = $repository->getByKey($key);
			if (!$state || SharedSessionScope::version($state) === 0)
			{
				$legacy[] = $session;

				continue;
			}
			if (SharedSessionScope::version($state) !== 1)
			{
				continue;
			}
			$marked = $repository->withLockedDocument($key, function () use ($session, $key, $repository): Result {
				$ids = [];
				$registry = new SessionRegistry($repository);
				$rows = DocumentStateRepository::fetchAllForUpdate(PlatformSessionTable::query()
					->setSelect(SharedEditingSchema::sessionFields())->where('HOST_DOC_KEY', $key)
					->where('DOCUMENT_SESSION_ID', (int)$session->getId()));
				foreach ($rows as $row)
				{
					if (!SharedSessionScope::matchesBinding($session, $row))
					{
						continue;
					}
					$result = $registry->markRevokeLocked($row);
					if (!$result->isSuccess())
					{
						return $result;
					}
					if ($result->getData()['pending'])
					{
						$ids[] = (int)$row['ID'];
					}
				}

				return (new Result())->setData(['registryIds' => $ids]);
			});
			$this->processMarked($marked, $repository);
		}
		$this->revokeExternalGrants($legacy);
		$this->finishLocalSessions($legacy);
		$this->deleteLocalSessions($legacy);
	}

	public function terminateSessionsByExternalLink(int $externalLinkId): void
	{
		if ((!$this->object instanceof File && !$this->object instanceof Folder) || $externalLinkId <= 0)
		{
			return;
		}
		$repository = new DocumentStateRepository();
		$objectId = (int)$this->object->getRealObjectId();
		$isFolder = $this->object instanceof Folder;
		$marked = $repository->withPrimaryTransaction(function () use ($repository, $objectId, $externalLinkId, $isFolder): Result {
			DocumentStateRepository::fetchForUpdate(ObjectTable::query()->setSelect(['ID'])->where('ID', $objectId));

			return (new AccessRevocation($repository, $this->protocolClient))
				->markExternalSourceLocked($objectId, $externalLinkId, $isFolder)
			;
		});
		if (!$marked->isSuccess())
		{
			return;
		}
		$this->processMarked($marked, $repository);
		$legacy = [];
		$filter = [
			'=SERVICE' => DocumentService::Vibeoffice->value,
			'=EXTERNAL_LINK_ID' => $externalLinkId,
			'=STATUS' => DocumentSession::STATUS_ACTIVE,
		];
		if (!$isFolder)
		{
			$filter['=OBJECT_ID'] = $objectId;
		}
		foreach (DocumentSession::getModelList(['filter' => $filter]) as $session)
		{
			if ($isFolder && !ObjectTable::query()->setSelect(['ID'])
				->where('REAL_OBJECT_ID', (int)$session->getObjectId())
				->where('TYPE', ObjectTable::TYPE_FILE)
				->where('PATH_CHILD.PARENT_ID', $objectId)
				->setLimit(1)->fetch())
			{
				continue;
			}
			$state = $repository->getByKey(HostDocumentKey::resolve($session));
			if (!$state || SharedSessionScope::version($state) === 0)
			{
				$legacy[] = $session;
			}
		}
		$this->revokeExternalGrants($legacy);
		$this->finishLocalSessions($legacy);
		$this->deleteLocalSessions($legacy);
	}

	private function processMarked(Result $marked, DocumentStateRepository $repository): void
	{
		if ($marked->isSuccess())
		{
			$revocation = new AccessRevocation($repository, $this->protocolClient);
			foreach ($marked->getData()['registryIds'] as $id)
			{
				$revocation->processBinding($id);
			}
		}
	}

	/** @param DocumentSession[] $localSessions */
	private function finishLocalSessions(array $localSessions): void
	{
		$sessionIds = [];
		foreach ($localSessions as $session)
		{
			$sessionIds[] = (int)$session->getId();
		}

		// The same chunking the finalizer applies to its session ids.
		foreach (array_chunk($sessionIds, 300) as $chunk)
		{
			DocumentSessionTable::deactivateByIds($chunk);
		}
	}

	/**
	 * @param DocumentSession[] $localSessions
	 */
	private function revokeExternalGrants(array $localSessions): void
	{
		foreach ($localSessions as $session)
		{
			// Idempotent: the platform returns 204 even without an active grant.
			$this->protocolClient->revokeGrant(
				HostDocumentKey::resolve($session),
				(string)$session->getUserId(),
			);
		}
	}

	/**
	 * @param DocumentSession[] $localSessions
	 */
	private function deleteLocalSessions(array $localSessions): void
	{
		foreach ($localSessions as $session)
		{
			$session->delete();
		}
	}

	/**
	 * @return DocumentSession[]
	 */
	private function getSessionsToTerminate(): array
	{
		$sessionsToDelete = [];
		if (!$this->object instanceof File)
		{
			return $sessionsToDelete;
		}

		$supportsUnifiedLink = $this->object->supportsUnifiedLink();
		$unifiedLinkAccessService = null;
		if ($supportsUnifiedLink)
		{
			$unifiedLinkAccessService = ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
		}

		$sessions = DocumentSession::getModelList([
			'filter' => [
				'=SERVICE' => DocumentService::Vibeoffice->value,
				'OBJECT_ID' => $this->object->getId(),
				'STATUS' => DocumentSession::STATUS_ACTIVE,
			],
		]);

		foreach ($sessions as $session)
		{
			$state = (new DocumentStateRepository())->getByKey(HostDocumentKey::resolve($session));
			if ($state && SharedSessionScope::version($state) !== 0)
			{
				if (SharedSessionScope::version($state) === 1 && !$this->hasSharedSourceAccess($session))
				{
					$sessionsToDelete[] = $session;
				}

				continue;
			}
			$sessionUserId = $session->getUserId();
			$securityContext = $this->object->getStorage()?->getSecurityContext($sessionUserId);
			if ($securityContext === null)
			{
				continue;
			}

			$unifiedLinkAccessLevel = UnifiedLinkAccessLevel::Denied;
			if ($supportsUnifiedLink && $unifiedLinkAccessService !== null)
			{
				$attachedObject = $session->getContext()?->getAttachedObject();
				$unifiedLinkAccessLevel = $unifiedLinkAccessService->check($this->object, $attachedObject, $sessionUserId);
			}

			$typeAndRightsNotMatch = false;

			if ($session->isView())
			{
				$canRead = $session->canRead($securityContext);
				$canReadByLink = $unifiedLinkAccessLevel->value >= UnifiedLinkAccessLevel::Read->value;

				$typeAndRightsNotMatch = !($canRead || $canReadByLink);
			}

			if ($session->isEdit())
			{
				$canEdit = $session->canEdit($securityContext);
				$canEditByLink = $unifiedLinkAccessLevel->value >= UnifiedLinkAccessLevel::Edit->value;

				$typeAndRightsNotMatch = !($canEdit || $canEditByLink);
			}

			if ($typeAndRightsNotMatch)
			{
				$sessionsToDelete[] = $session;
			}
		}

		return $sessionsToDelete;
	}

	private function hasSharedSourceAccess(DocumentSession $session): bool
	{
		$rows = PlatformSessionTable::query()->setSelect(SharedEditingSchema::sessionFields())
			->where('HOST_DOC_KEY', HostDocumentKey::resolve($session))
			->where('DOCUMENT_SESSION_ID', (int)$session->getId())->fetchAll()
		;
		foreach ($rows as $row)
		{
			if (!SharedSessionScope::matchesBinding($session, $row))
			{
				continue;
			}
			if ((int)$row['ACCESS_REVOKE_STATE'] !== 0
				|| ($row['ACCESS_EXPIRES_AT'] !== null && $row['ACCESS_EXPIRES_AT']->getTimestamp() <= time()))
			{
				return false;
			}
			$actor = (int)$row['ACTOR_USER_ID'];
			$access = SharedSessionScope::access($row);
			$confirmations = $access['context']['confirmationFingerprints'] ?? [];
			$seedObjectIds = array_filter([
				(int)$this->object->getId(),
				(int)$this->object->getRealObjectId(),
				(int)$session->getObjectId(),
			]);
			$seedExternalLinkIds = in_array($row['ACCESS_SOURCE_TYPE'], ['external', 'unified'], true)
				? [(int)$row['ACCESS_SOURCE_ID']]
				: [];
			$seedAttachedIds = $row['ACCESS_SOURCE_TYPE'] === 'attached' ? [(int)$row['ACCESS_SOURCE_ID']] : [];
			$includeObjectPaths = $row['ACCESS_SOURCE_TYPE'] !== 'external' || array_key_exists('classicLink', $confirmations);

			return DocumentStateRepository::withPreLockedObjects(
				$seedObjectIds,
				$seedExternalLinkIds,
				[],
				$seedAttachedIds,
				$includeObjectPaths,
				function () use ($row, $actor, $access, $confirmations): bool {
					if (in_array($row['ACCESS_SOURCE_TYPE'], ['external', 'unified'], true))
					{
						$link = ExternalLink::loadById((int)$row['ACCESS_SOURCE_ID']);
						$revisions = AccessRevocation::sourceRevisions([(int)$row['ACCESS_SOURCE_ID']]);
						$isGuest = $actor <= 0 || (UserTable::getById($actor)->fetch()['EXTERNAL_AUTH_ID'] ?? null)
							=== DocumentEditorUser::EXTERNAL_AUTH_ID;
						$linkValid = $link && $this->object instanceof File
							&& DocumentStateRepository::externalLinkMatchesFile($link, $this->object)
							&& !$link->isSpecificVersion()
							&& AccessRevocation::hasCurrentSourceRevision($row, $revisions[(int)$row['ACCESS_SOURCE_ID']] ?? null)
							&& ($row['ACCESS_SOURCE_TYPE'] === 'unified' && !$isGuest
								? (new SharedEditingAccess())->hasShortLinkEditAccess(
									$this->object,
									(int)$row['ACCESS_SOURCE_ID'],
									$actor,
									array_key_exists('unifiedLink', $confirmations) ? 'internal-unified' : 'native',
								)
								: $link->getAccessRight() === ExternalLink::ACCESS_RIGHT_EDIT)
							&& ($link->getDeathTime() === null || $link->getDeathTime()->getTimestamp() > time());
						if (!$linkValid)
						{
							return false;
						}
						if (!array_key_exists('unifiedLink', $confirmations))
						{
							return true;
						}
					}
					if (array_key_exists('unifiedLink', $confirmations))
					{
						return in_array($row['ACCESS_SOURCE_TYPE'], ['internal', 'unified'], true) && $actor > 0
							&& ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class)
								->checkFreshUnifiedLinkSource($this->object, $actor)->canEdit()
							&& $confirmations['unifiedLink'] === UnifiedLinkSourceRevision::fingerprint(
								$this->object, DocumentStateRepository::hasOpenTransaction());
					}
					$type = $row['ACCESS_SOURCE_TYPE'];
					$attachedId = $access['context']['attachedObjectId'] ?? null;
					if (($type === 'internal' && ($attachedId !== null || (int)$row['ACCESS_SOURCE_ID'] !== 0))
						|| ($type === 'attached' && (!is_int($attachedId) || $attachedId <= 0
							|| $attachedId !== (int)$row['ACCESS_SOURCE_ID']))
						|| !in_array($type, ['internal', 'attached'], true))
					{
						return false;
					}

					return (new SharedEditingAccess())->hasNativeEditAccess($this->object,
						new DocumentSessionContext((int)$this->object->getRealObjectId(), $attachedId), $actor);
				},
			);
		}

		return false;
	}
}
