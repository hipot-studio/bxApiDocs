<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Dto;

enum ApplicationStatus
{
	case Local;
	case Free;
	case Paid;
	case Demo;
	case Trial;
	case Subscription;
	case Unknown;
}
