<?php

declare(strict_types=1);

namespace Bitrix\Disk\Destination;

enum DestinationOperation: string
{
	case Move = 'move';
	case Copy = 'copy';
}
