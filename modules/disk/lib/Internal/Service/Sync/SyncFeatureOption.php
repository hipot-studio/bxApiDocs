<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync;

use Bitrix\Disk\Driver;
use Bitrix\Main\Config\Option;

/**
 * Availability switch of the disk.sync.* REST contour.
 *
 * Owns the option name and the reading of it, so that the contour has a single place to ask
 * instead of every caller spelling out the option key.
 */
final class SyncFeatureOption
{
	public const OPTION_NAME = 'disk_enable_sync_rest';

	public function isEnabled(): bool
	{
		return Option::get(Driver::INTERNAL_MODULE_ID, self::OPTION_NAME, 'N') === 'Y';
	}
}
