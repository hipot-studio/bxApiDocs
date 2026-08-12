<?php

declare(strict_types=1);

namespace Bitrix\Disk\Rest\Service\Search;

enum SearchType: string
{
	case File = 'file';
	case Folder = 'folder';
	case All = 'all';
}
