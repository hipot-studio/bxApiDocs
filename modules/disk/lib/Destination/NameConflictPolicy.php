<?php

declare(strict_types=1);

namespace Bitrix\Disk\Destination;

enum NameConflictPolicy: string
{
	case Report = 'report';
	case KeepBoth = 'keep_both';
}
