<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Enum;

enum ActivityNodeType: string
{
	case COMPLEX = 'complex';
	case SIMPLE = 'simple';
	case TRIGGER = 'trigger';
	case TOOL = 'tool';
}
