<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Result;

enum Type: string
{
	case Default = 'default';
	case Ai = 'ai';
}
