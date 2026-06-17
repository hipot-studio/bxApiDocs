<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\User;

enum Gender: string
{
	case Male = 'M';
	case Female = 'F';
	case None = 'N';
}
