<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\ExternalLink;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Configuration;

class ExternalLinkAccessService
{
	/**
	 * Tells whether the current user is allowed to manage the external link of the object.
	 *
	 * The unified link of the object is taken into account only on request and only when the object supports it.
	 *
	 * @param BaseObject $object
	 * @param bool $shouldCheckUnifiedLink
	 * @return bool
	 */
	public function canManageExternalLink(BaseObject $object, bool $shouldCheckUnifiedLink = false): bool
	{
		$realObject = $object->getRealObject();
		if (!$realObject)
		{
			return false;
		}

		$shouldCheckUnifiedLink = $shouldCheckUnifiedLink && $realObject->supportsUnifiedLink();
		$securityContext = $realObject->getStorage()?->getCurrentUserSecurityContext($shouldCheckUnifiedLink);

		return $securityContext && $realObject->canManageExternalLink($securityContext);
	}

	/**
	 * Tells whether public links are allowed by the portal setting.
	 *
	 * @return bool
	 */
	public function isExternalLinkEnabledOnPortal(): bool
	{
		return (bool)Configuration::isPossibleToShowExternalLinkControl();
	}
}
