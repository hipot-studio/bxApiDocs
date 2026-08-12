<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\User;

enum UserAttribute: string
{
	case FirstOpenedCatalog = 'CATALOG_FIRST_OPENED_AT';
}
