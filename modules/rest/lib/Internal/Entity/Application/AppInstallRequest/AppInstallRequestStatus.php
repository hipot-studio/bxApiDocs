<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application\AppInstallRequest;

enum AppInstallRequestStatus: string
{
	case Pending = 'pending';
	case Installed = 'installed';
	case Rejected = 'rejected';
}
