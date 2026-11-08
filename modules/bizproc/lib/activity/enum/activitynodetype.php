<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Enum;

enum ActivityNodeType: string
{
	case COMPLEX = 'complex';
	case SIMPLE = 'simple';
	case TRIGGER = 'trigger';
	case FRAME = 'frame';
	case POOL = 'pool';
	case TOOL = 'tool';
	case SERVICE = 'services';
	case OPERATORS = 'operators';
}
