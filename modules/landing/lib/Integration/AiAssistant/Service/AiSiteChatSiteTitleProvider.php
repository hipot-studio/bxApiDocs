<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Internals\SiteTable;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Rights;

/**
 * The title of the site bound to the chat key of a user, made plain for the chat.
 *
 * The key names the binding row in either generation (AiSiteBindingService::parseChatEntityId()); the title is
 * given to the owner of the row only while he can read its site.
 */
class AiSiteChatSiteTitleProvider
{
	private AiSiteBindingService $bindingService;

	public function __construct(?AiSiteBindingService $bindingService = null)
	{
		$this->bindingService = $bindingService ?? new AiSiteBindingService();
	}

	/**
	 * The title for the row of the current user.
	 */
	public function findByEntityId(?string $entityId): ?string
	{
		if ($entityId === null)
		{
			return null;
		}

		try
		{
			$userId = $this->getCurrentUserId();
		}
		catch (\Throwable)
		{
			return null;
		}

		return $this->findByEntityIdForUser($entityId, $userId);
	}

	/**
	 * The title for the row of the given user: a path without a current user (an agent, a step of the
	 * generation) names the owner of the row explicitly.
	 *
	 * Null for an invalid key, a row of another user, a row without a site, a site the user cannot read,
	 * a deleted or untitled site and for any failure.
	 */
	public function findByEntityIdForUser(string $entityId, int $userId): ?string
	{
		$bindingId = (int)($this->bindingService->parseChatEntityId($entityId)['bindingId'] ?? 0);
		if ($userId <= 0 || $bindingId <= 0)
		{
			return null;
		}

		try
		{
			$row = $this->bindingService->getByIdForUser($bindingId, $userId);
			$siteId = (int)($row['SITE_ID'] ?? 0);
			if ($siteId <= 0 || !$this->canUserReadSite($userId, $siteId))
			{
				return null;
			}

			$title = AiSiteChatPlainText::title((string)$this->findSiteTitle($siteId));
		}
		catch (\Throwable)
		{
			return null;
		}

		return $title !== '' ? $title : null;
	}

	protected function getCurrentUserId(): int
	{
		return Manager::getUserId();
	}

	/**
	 * Rights are those of the given user, whatever checks the process switched off.
	 */
	protected function canUserReadSite(int $userId, int $siteId): bool
	{
		return in_array(Rights::ACCESS_TYPES['read'], Rights::getUserOperationsForSite($userId, $siteId), true);
	}

	protected function findSiteTitle(int $siteId): ?string
	{
		$row = SiteTable::query()
			->setSelect(['TITLE'])
			->where('ID', $siteId)
			->where('DELETED', 'N')
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? (string)($row['TITLE'] ?? '') : null;
	}
}
