<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingAccessDeniedException;
use Bitrix\Crm\V2\Internal\Integration\Intranet\IntranetUserGateway;
use Bitrix\Crm\V2\Public\Service\Tracking\TrackingSettingsAccess;

/**
 * The rights to the channels, the sources and the manual expenses of the end-to-end analytics: reading needs an
 * intranet user with access to CRM, a change needs the right to the tracking settings as well. An anonymous user,
 * an extranet user and a collaber have neither, whatever their role in CRM, as in the UI.
 *
 * @internal
 */
final class TrackingAccess
{
	/**
	 * @param Container|null $container null - the container of the moment of the check
	 * @param TrackingSettingsAccess|null $settingsAccess null - the settings right of the same container
	 * @param IntranetUserGateway|null $intranetUsers null - the kind of the user as the intranet module tells it
	 */
	public function __construct(
		private readonly ?Container $container = null,
		private readonly ?TrackingSettingsAccess $settingsAccess = null,
		private readonly ?IntranetUserGateway $intranetUsers = null,
	)
	{
	}

	/**
	 * The coarse check of the tools of MCP asks the same as {@see self::assertCanRead()}.
	 */
	public function canRead(int $userId): bool
	{
		return $userId > 0
			&& ($this->intranetUsers ?? new IntranetUserGateway())->isIntranetUser($userId)
			&& $this->canReadCrm($userId)
		;
	}

	/**
	 * @throws TrackingAccessDeniedException there is no user, the user is not an intranet one or has no access
	 * to CRM
	 */
	public function assertCanRead(int $userId): void
	{
		if (!$this->canRead($userId))
		{
			throw new TrackingAccessDeniedException();
		}
	}

	/**
	 * @throws TrackingAccessDeniedException the user may not read or may not change the tracking settings
	 */
	public function assertCanModify(int $userId): void
	{
		$this->assertCanRead($userId);

		$settingsAccess = $this->settingsAccess ?? new TrackingSettingsAccess($this->container);
		if (!$settingsAccess->canModify($userId))
		{
			throw new TrackingAccessDeniedException();
		}
	}

	private function canReadCrm(int $userId): bool
	{
		$container = $this->container ?? Container::getInstance();

		return $container->getUserPermissions($userId)->entityType()->canReadSomeItemsInCrm();
	}
}
