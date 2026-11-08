<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Storage;

enum StorageType: string
{
	case User = 'user';
	case Common = 'common';
	case Collab = 'collab';
	case Project = 'project';
	case Group = 'group';
}
