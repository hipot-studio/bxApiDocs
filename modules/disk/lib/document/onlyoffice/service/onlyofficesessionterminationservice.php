<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\OnlyOffice\Service;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\OnlyOffice\Clients\CommandService\CommandServiceClientFactory;
use Bitrix\Disk\Document\OnlyOffice\Clients\CommandService\CommandServiceClientInterface;
use Bitrix\Disk\Document\SessionTerminationService;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;
use Psr\Container\NotFoundExceptionInterface;

class OnlyOfficeSessionTerminationService implements SessionTerminationService
{
	private readonly CommandServiceClientInterface $commandServiceClient;

	/**
	 * @param BaseObject $object
	 * @throws ConfigurationException
	 * @throws ObjectNotFoundException
	 * @throws NotFoundExceptionInterface
	 */
	public function __construct(
		private readonly BaseObject $object,
	)
	{
		$this->commandServiceClient = CommandServiceClientFactory::createCommandServiceClient();
	}

	public function terminateSessionsWithInsufficientRights(): void
	{
		$sessionsToTerminate = $this->getSessionsToTerminate();

		if (empty($sessionsToTerminate))
		{
			return;
		}

		$this->terminateExternalSession($sessionsToTerminate);
		$this->deleteLocalSession($sessionsToTerminate);
	}

	/**
	 * @param DocumentSession[] $localSessions
	 * @return void
	 */
	private function deleteLocalSession(array $localSessions): void
	{
		foreach ($localSessions as $session)
		{
			$session->delete();
		}
	}

	/**
	 * @param DocumentSession[] $localSessions
	 * @return void
	 */
	private function terminateExternalSession(array $localSessions): void
	{
		foreach ($localSessions as $session)
		{
			$documentKey = $session->getExternalHash();
			$userIds = [(string)$session->getUserId()];
			$this->commandServiceClient->drop($documentKey, $userIds);
		}
	}

	private function getSessionsToTerminate(): array
	{
		$sessionsToDelete = [];
		if (!$this->object instanceof File)
		{
			return $sessionsToDelete;
		}

		$supportsUnifiedLink = $this->object->supportsUnifiedLink();
		if ($supportsUnifiedLink)
		{
			$unifiedLinkAccessService = ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
		}

		$sessions = DocumentSession::getModelList([
			'filter' => [
				'OBJECT_ID' => $this->object->getId(),
				'STATUS' => DocumentSession::STATUS_ACTIVE,
			],
		]);

		foreach ($sessions as $session)
		{
			$typeAndRightsNotMatch = false;

			$sessionUserId = $session->getUserId();
			$securityContext = $this->object->getStorage()?->getSecurityContext($sessionUserId);
			if ($securityContext === null)
			{
				continue;
			}

			$unifiedLinkAccessLevel = UnifiedLinkAccessLevel::Denied;
			if ($supportsUnifiedLink)
			{
				$attachedObject = $session->getContext()?->getAttachedObject();
				$unifiedLinkAccessLevel = $unifiedLinkAccessService->check($this->object, $attachedObject, $sessionUserId);
			}

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
