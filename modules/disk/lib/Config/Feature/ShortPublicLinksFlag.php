<?php

declare(strict_types=1);

namespace Bitrix\Disk\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Lets the facade of public addresses issue the short form /docs/pub/s/{code}/ of a manual link of a file.
 *
 * The flag gates the issue only: codes are reserved and already issued short addresses keep opening while it is
 * off. It is turned on for a portal after its owner has checked the route and the openings on the origin the
 * portal is configured with; a changed origin turns it off until that check is repeated. There is no runtime
 * readiness check, so a missing confirmation means the flag stays off.
 */
final class ShortPublicLinksFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
