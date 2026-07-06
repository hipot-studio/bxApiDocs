<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

enum AppScopeRequestStatus: string
{
	case Pending = 'pending';
	case Approved = 'approved';
	case Rejected = 'rejected';
}
