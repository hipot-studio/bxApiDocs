<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller;

use Bitrix\Intranet\Internal\Service\SystemUser\SystemUserIntegrationService;

trait SystemUserIntegrationTrait
{
	protected function getSystemUserIntegrationService(): SystemUserIntegrationService
	{
		return new SystemUserIntegrationService();
	}
}
