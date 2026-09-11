<?php

namespace Bitrix\Sign\Type\Document\Folder;

use Bitrix\Sign\Type\ValuesTrait;

enum EntityType: string
{
	use ValuesTrait;

	case MEMBER = 'member';
	case FOLDER = 'folder';
}
