<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum;

enum ServersTypesEnum: string
{
	case Regular = 'regular'; // regular servers for all cloud portals
	case Booster = 'booster'; // dedicated servers are only for portals that have purchased a booster
}
