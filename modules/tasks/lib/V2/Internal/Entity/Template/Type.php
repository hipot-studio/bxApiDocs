<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

enum Type: string
{
	case NewUsers = 'new_users';
	case Usual = 'usual';
}
