<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Entities\Enum;

enum RequestStatus: string
{
	case New = 'N';
	case Sent = 'S';
	case Received = 'R';
	case Error = 'E';
}