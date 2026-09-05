<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Service;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\SessionTerminationService;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientFactory;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientInterface;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;
use Psr\Container\NotFoundExceptionInterface;

/**
 * vibeoffice implementation of {@see SessionTerminationService} (P4.T4).
 *
 * Built by analogy with {@see \Bitrix\Disk\Document\OnlyOffice\Service\OnlyOfficeSessionTerminationService}:
 * it finds active `SERVICE='vibeoffice'` sessions of the object whose stored type no
 * longer matches the user's current rights (a view session without read, an edit session
 * without edit, accounting for the unified link), revokes their platform grant
 * (`DELETE /v1/documents/{docKey}/grants/{userId}`) and then deletes the local session.
 *
 * The single entry point is the existing sharing-change UI path
 * (`disk/lib/controller/accessrights.php`, background job) via
 * {@see \Bitrix\Disk\Document\SessionTerminationServiceFactory}; no new event
 * subscriptions are introduced. Parity with OnlyOffice/Flipchart: eager revoke happens
 * only from that UI point; every other path relies on the lazy re-computation of `mode`
 * at `open`/`refresh` (Q-2). `revokeGrant` is idempotent (the platform returns `204` even
 * when no grant existed), so the revoke is best-effort enforcement: the platform itself
 * emits `session-closed{revoked}` over SSE.
 */
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

		$this->revokeExternalGrants($sessionsToTerminate);
		$this->deleteLocalSessions($sessionsToTerminate);
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
				$session->getExternalHash(),
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
}
