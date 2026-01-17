<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionContext;
use Bitrix\Disk\Document\SessionTerminationService;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Main\DI\ServiceLocator;

class BoardSessionTerminationService implements SessionTerminationService
{
	/**
	 * @param BaseObject $object
	 */
	public function __construct(
		private readonly BaseObject $object,
	)
	{
	}

	public function terminateSessionsWithInsufficientRights(): void
	{
		if (!$this->object instanceof File)
		{
			return;
		}

		$unallowedSessions = $this->findUnallowedSessions($this->object);

		if (empty($unallowedSessions))
		{
			return;
		}

		$this->markSessionAsNonActive($unallowedSessions);
		BoardService::kickUnallowedUsers($unallowedSessions, $this->object);
	}

	public function findUnallowedSessions(File|AttachedObject $object): array
	{
		$manager = new SessionManager();
		$unifiedLinkAccessService = ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);

		if ($object instanceof File)
		{
			$manager->setFile($object);
			$sessionContext = new DocumentSessionContext(
				(int)$object->getId(),
				null,
				null,
			);

			$supportsUnifiedLink = $object->supportsUnifiedLink();
		}
		else
		{
			$manager->setFile($object->getFile());
			$sessionContext = new DocumentSessionContext(
				(int)$object->getFileId(),
				(int)$object->getId(),
				null,
			);

			$supportsUnifiedLink = (bool)$object->getFile()?->supportsUnifiedLink();
		}

		$manager->setSessionContext($sessionContext);
		$sessions = $manager->findAllSessions();
		$unallowed = [];

		foreach ($sessions as $session)
		{
			if ($session->getUserId() < 0)
			{
				continue;
			}

			$userId = $session->getUserId();
			if ($object instanceof File)
			{
				$context = $object->getStorage()?->getSecurityContext($session->getUserId());
				$canUpdate = $object->canUpdate($context);
				$canRead = $canUpdate || $object->canRead($context);

				$canUpdateByLink = false;
				$canReadByLink = false;

				if (!$canUpdate && $supportsUnifiedLink)
				{
					$attachedObject = $session->getContext()?->getAttachedObject();
					$unifiedLinkAccessLevel = $unifiedLinkAccessService->check($object, $attachedObject, $userId);
					$canUpdateByLink = $unifiedLinkAccessLevel === UnifiedLinkAccessLevel::Edit;
					$canReadByLink = $canUpdateByLink || $unifiedLinkAccessLevel === UnifiedLinkAccessLevel::Read;
				}
			}
			else
			{
				/** @var AttachedObject $object */
				$canUpdate = $object->canUpdate($userId);
				$canRead = $canUpdate || $object->canRead($userId);
				$canUpdateByLink = false;
				$canReadByLink = false;

				if (!$canUpdate && $supportsUnifiedLink)
				{
					$unifiedLinkAccessLevel = $unifiedLinkAccessService->check($object->getFile(), $object, $userId);
					$canUpdateByLink = $unifiedLinkAccessLevel === UnifiedLinkAccessLevel::Edit;
					$canReadByLink = $canUpdateByLink || $unifiedLinkAccessLevel === UnifiedLinkAccessLevel::Read;
				}
			}

			if (
				(
					$session->isEdit()
					&& !$canUpdate
					&& !$canUpdateByLink
				)
				|| (
					$session->isView()
					&& !$canRead
					&& !$canReadByLink
				)
			)
			{
				$unallowed[] = $session;
			}
		}

		return $unallowed;
	}

	/**
	 * @param DocumentSession[] $sessions
	 * @return void
	 */
	private function markSessionAsNonActive(array $sessions): void
	{
		foreach ($sessions as $session)
		{
			$session->setAsNonActive();
		}
	}
}
