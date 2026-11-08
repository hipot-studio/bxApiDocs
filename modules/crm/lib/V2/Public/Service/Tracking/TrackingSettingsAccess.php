<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Service\Tracking;

use Bitrix\Crm\Service\Container;

/**
 * The right to change the settings of the end-to-end analytics: sources, channels and the rest of the
 * tracking configuration. It is the CRM settings right; an anonymous user never has it.
 */
final class TrackingSettingsAccess
{
	private readonly Container $container;

	public function __construct(?Container $container = null)
	{
		$this->container = $container ?? Container::getInstance();
	}

	public function canModify(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return $this->container->getUserPermissions($userId)->isCrmAdmin();
	}
}
