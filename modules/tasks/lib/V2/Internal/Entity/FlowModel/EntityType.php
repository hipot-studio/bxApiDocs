<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\FlowModel;

enum EntityType: string
{
	case Department = 'D';
	case DepartmentRecursive = 'DR';
}
