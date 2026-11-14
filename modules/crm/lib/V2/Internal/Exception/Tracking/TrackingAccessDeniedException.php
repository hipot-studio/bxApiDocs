<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Tracking;

use RuntimeException;

/**
 * The user may not read the channels, the sources and the expenses of the end-to-end analytics, or may not
 * change them.
 *
 * @internal
 */
final class TrackingAccessDeniedException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Access to the end-to-end analytics settings is denied.');
	}
}
