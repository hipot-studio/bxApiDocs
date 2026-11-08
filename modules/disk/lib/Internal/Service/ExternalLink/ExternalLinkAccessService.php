<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\ExternalLink;

use Bitrix\Disk\BaseObject;

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
}
