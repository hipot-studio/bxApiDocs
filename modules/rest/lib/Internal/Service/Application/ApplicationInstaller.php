<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Application;

use Bitrix\Main;
use Bitrix\Main\Security\Random;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Entity\Application\AppAttributeCode;
use Bitrix\Rest\Internal\Exception\Application\ApplicationNotInstalledException;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Internal\Repository\IntegrationRepository;
use Bitrix\Rest\Preset\Provider;
use Bitrix\Rest\Enum\Integration\ElementCodeType;

class ApplicationInstaller
{
	public function __construct(
		private readonly AppRepository
		$appRepository,
		private readonly IntegrationRepository $integrationRepository = new IntegrationRepository(),
	) {}

	/**
	 * @throws Main\SystemException
	 * @throws Main\Repository\Exception\PersistenceException
	 */
	public function installAsLocal(
		int $userId,
		App $app,
		bool $onlyApi,
		?string $applicationToken = null,
	): App
	{
		$existingIntegration = null;
		if ($app->getClientId())
		{
			$existingIntegration = $this->integrationRepository->getByApplicationExternalId($app->getClientId());
		}

		$integrationResult = Provider::saveIntegration(
			[
				'TITLE' => $app->getAppName(),
				'SCOPE' => $app->getScope(),
				'APPLICATION_NEEDED' => 'Y',
				'APPLICATION_URL_HANDLER' => $app->getUrl(),
				'APPLICATION_URL_INSTALL' => $app->getUrlInstall() ?? '',
				'APPLICATION_ONLY_API' => $onlyApi ? 'Y' : 'N',
				'APPLICATION_MOBILE' => $app->isMobile() ? 'Y' : 'N',
				'APPLICATION_LANG_NAME' => $app->getLangs()->getMenuNames(),
				'WIDGET_NEEDED' => 'N',
				'OUTGOING_NEEDED' => 'N',
			],
			ElementCodeType::APPLICATION->value,
			$existingIntegration?->getId(),
			$userId
		);

		if (!$integrationResult['status'])
		{
			throw new Main\SystemException(
				implode('; ', $integrationResult['errors'] ?? ['Failed to create integration'])
			);
		}

		$integration = $this->integrationRepository->getById($integrationResult['ID']);
		$appId = $integration->getAppId();

		$savedApp = $this->appRepository->getById($appId);

		if ($applicationToken !== null)
		{
			$savedApp->setApplicationToken($applicationToken);
		}
		$savedApp->setExternalAttributes($app->getExternalAttributes());

		$this->appRepository->save($savedApp);

		$this->fireApplicationInstalled($integration->getId(), (int)$savedApp->getId(), $userId);

		return $savedApp;
	}

	/**
	 * @throws Main\SystemException
	 * @throws Main\Repository\Exception\PersistenceException
	 */
	public function installAsPersonal(
		int $userId,
		App $app,
		bool $onlyApi,
		?string $applicationToken = null,
	): App
	{
		//region Needs to refactor into just Application installation
		$existingIntegration = null;
		if ($app->getClientId())
		{
			$existingIntegration = $this->integrationRepository->getByApplicationExternalId($app->getClientId());
		}

		$integrationResult = Provider::saveIntegration(
			[
				'TITLE' => $app->getAppName(),
				'SCOPE' => $app->getScope(),
				'APPLICATION_NEEDED' => 'Y',
				'IS_APPLICATION_PERSONAL' => 'Y',
				'APPLICATION_URL_HANDLER' => $app->getUrl(),
				'APPLICATION_URL_INSTALL' => $app->getUrlInstall() ?? '',
				'APPLICATION_ONLY_API' => $onlyApi ? 'Y' : 'N',
				'APPLICATION_MOBILE' => $app->isMobile() ? 'Y' : 'N',
				'APPLICATION_LANG_NAME' => $app->getLangs()->getMenuNames(),
				'WIDGET_NEEDED' => 'N',
				'OUTGOING_NEEDED' => 'N',
			],
			ElementCodeType::APPLICATION->value,
			$existingIntegration?->getId(),
			$userId
		);
		// endregion

		if (!$integrationResult['status'])
		{
			throw new ApplicationNotInstalledException(
				implode('; ', $integrationResult['errors'] ?? ['Failed to create integration'])
			);
		}

		$integration = $this->integrationRepository->getById($integrationResult['ID']);
		$appId = $integration->getAppId();

		$savedApp = $this->appRepository->getById($appId);

		$savedApp->setAccess('U' . $userId);
		$savedApp->setAttribute(AppAttributeCode::OwnerUserId->value, (string)$userId);
		if ($applicationToken !== null)
		{
			$savedApp->setApplicationToken($applicationToken);
		}
		$savedApp->setExternalAttributes($app->getExternalAttributes());

		$this->appRepository->save($savedApp);

		$this->fireApplicationInstalled($integration->getId(), (int)$savedApp->getId(), $userId);

		return $savedApp;
	}

	private function fireApplicationInstalled(int $integrationId, int $appId, int $userId): void
	{
		$event = new Main\Event('rest', 'onApplicationInstalled', [
			'integrationId' => $integrationId,
			'appId' => $appId,
			'userId' => $userId,
		]);
		$event->send();
	}
}
