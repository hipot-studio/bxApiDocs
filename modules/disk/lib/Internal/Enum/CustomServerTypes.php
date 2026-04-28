<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum;

/**
 * Contains all possible types of custom servers.
 */
enum CustomServerTypes: string
{
	case R7 = 'r7';
	case OnlyOffice = 'onlyoffice';
}
