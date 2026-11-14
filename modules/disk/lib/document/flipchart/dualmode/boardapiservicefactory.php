<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

use Bitrix\Disk\Document\Flipchart\BoardApiService;
use Bitrix\Disk\Document\Flipchart\JwtService;

/**
 * Builds a board service client for a resolved profile: the single place where a profile turns into
 * a network address.
 */
interface BoardApiServiceFactory
{
	public function create(?ServiceProfile $profile): BoardApiService;

	public function createForServerRead(?ServiceProfile $profile, JwtService $jwtService): BoardApiService;
}
