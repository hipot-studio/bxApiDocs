<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository;

enum SortDirection: string
{
	case Asc = 'ASC';
	case Desc = 'DESC';
}
