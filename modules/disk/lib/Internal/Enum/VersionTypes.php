<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum;

/**
 * Contains all supported types of versions.
 */
enum VersionTypes: string
{
	case Dotted = 'dotted'; // for e.g. 2025.3.2.1000
}
