<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Entities\Enum;

enum QuestStatus: string
{
	case New = 'N';
	case Completed = 'C';
	case Error = 'E';
}