<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Debugger;

enum TraceType: string
{
	case Activity = 'activity';

	case Condition = 'condition';

	case Variable = 'variable';

	case Event = 'event';

	case Error = 'error';

	case Log = 'log';
}
