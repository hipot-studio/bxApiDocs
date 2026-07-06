<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

enum FolderType: string
{
	case Personal = 'personal';
	case System = 'system';
}
