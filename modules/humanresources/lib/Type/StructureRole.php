<?php

namespace Bitrix\HumanResources\Type;

use Bitrix\HumanResources\Internals\Trait\ValuesTrait;

enum StructureRole: int
{
 	case HEAD = 1;
 	case EMPLOYEE = 2;
 	case DEPUTY_HEAD = 3;

	use ValuesTrait;
}
