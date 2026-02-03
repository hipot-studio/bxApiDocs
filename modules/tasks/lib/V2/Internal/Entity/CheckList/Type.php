<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\CheckList;

enum Type: string
{
	case Task = 'task';
	case Template = 'template';
}
