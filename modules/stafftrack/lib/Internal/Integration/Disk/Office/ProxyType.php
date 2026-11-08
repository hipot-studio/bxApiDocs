<?php

namespace Bitrix\StaffTrack\Internal\Integration\Disk\Office;

use Bitrix\Disk\ProxyType\Base;

/**
 * Type of the office photo storage. The base of the proxy types is taken rather than the disk or the
 * common one: those answer about access from the rights lists of the disk, and the module would have
 * to keep such a list in step with the rule of the office directory.
 *
 * The storage is hidden - it has no page of its own on the portal, and its files are shown inside the
 * office card - so it carries neither a base url nor a picture.
 */
class ProxyType extends Base
{
	final public function getSecurityContextByUser($user): SecurityContext
	{
		return new SecurityContext($user);
	}

	final public function getStorageBaseUrl(): string
	{
		return '';
	}

	final public function getEntityImageSrc($width, $height): string
	{
		return '';
	}
}
