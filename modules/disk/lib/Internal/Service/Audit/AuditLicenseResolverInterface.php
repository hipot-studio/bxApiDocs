<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

interface AuditLicenseResolverInterface
{
	public function isAvailable(): bool;
}
