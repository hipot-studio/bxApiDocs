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
	 * @param BaseObject $object
	 * @return bool
	 */
	public function canManageExternalLink(BaseObject $object): bool
	{
		$realObject = $object->getRealObject();
		if (!$realObject)
		{
			return false;
		}

		$securityContext = $realObject->getStorage()?->getCurrentUserSecurityContext();

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
