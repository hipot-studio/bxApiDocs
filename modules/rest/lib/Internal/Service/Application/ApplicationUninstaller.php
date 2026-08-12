<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Application;

use Bitrix\Main;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Internal\Repository\IntegrationRepository;
use Bitrix\Rest\Internal\Service\Security\SecurityAuditLogger;
use Bitrix\Rest\Preset\Provider;

class ApplicationUninstaller
{
	public function __construct(
		private readonly AppRepository $appRepository,
		private readonly IntegrationRepository $integrationRepository = new IntegrationRepository(),
		private readonly SecurityAuditLogger $securityAuditLogger = new SecurityAuditLogger(),
	) {}

	/**
	 * @throws Main\Repository\Exception\PersistenceException
	 */
	public function uninstall(App $app): void
	{
		$this->uninstallByUser($app);
	}

	/**
	 * @throws Main\Repository\Exception\PersistenceException
	 */
	public function uninstallByUser(App $app, ?int $userId = null): void
	{
		$integration = $this->integrationRepository->getByApplicationExternalId($app->getClientId());
		if ($integration !== null)
		{
			Provider::deleteIntegration($integration->getId(), $userId);
		}
		else
		{
			$this->appRepository->delete($app->getId());
		}

		$this->securityAuditLogger->logAppUninstalled(
			actingUserId: $userId ?? 0,
			appId: (int)$app->getId(),
			clientId: (string)$app->getClientId(),
		);
	}
}
