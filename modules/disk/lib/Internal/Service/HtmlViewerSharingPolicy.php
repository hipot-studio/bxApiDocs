<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\User;

/**
 * Decides whether the html viewer page offers the access popup, by the same rule the office editors
 * apply ({@see \CDiskFileEditorVibeofficeComponent::shouldDisableSharingButton()}): any portal user
 * who reached the page gets the button, object rights do not affect it. What a user without rights
 * can do there is limited by the popup itself: {@see \Bitrix\Disk\Controller\AccessRights} reports
 * members and public link as read-only and refuses the write actions.
 *
 * Unlike the editors this page has no upsell surface, so a portal without the sharing feature gets
 * no button at all.
 */
class HtmlViewerSharingPolicy
{
	private const FEATURE = 'disk_file_sharing';

	public function isAvailableForUser(?int $userId): bool
	{
		if ($userId === null || $userId <= 0)
		{
			return false;
		}

		return $this->isFeatureEnabled() && $this->isPortalUser($userId);
	}

	protected function isFeatureEnabled(): bool
	{
		return Bitrix24Manager::isFeatureEnabled(self::FEATURE);
	}

	/**
	 * An anonymous reader or an external user holds the link, not a place to configure access from.
	 */
	protected function isPortalUser(int $userId): bool
	{
		$user = User::loadById($userId);

		return $user !== null && ($user->isIntranetUser() || $user->isCollaber());
	}
}
