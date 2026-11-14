<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Integration\Rest;

use Bitrix\Main\Loader;
use Bitrix\Rest\Public\Provider;
use Bitrix\Rest\Public\Provider\Dto\ApplicationStatus;

final class ApplicationProvider
{
	public function findByCode(string $code): array
	{
		if (!Loader::includeModule('rest'))
		{
			return [];
		}

		$app = (new Provider\ApplicationProvider())->findByCode($code);
		if ($app === null)
		{
			return [];
		}

		return [
			'ID' => $app->getId(),
			'CLIENT_ID' => $app->getClientId(),
			'CODE' => $app->getCode(),
			'ACTIVE' => self::legacyFlag($app->isActive()),
			'INSTALLED' => self::legacyFlag($app->isInstalled()),
			'URL' => $app->getUrl(),
			'URL_DEMO' => $app->getDemoUrl(),
			'URL_INSTALL' => $app->getInstallUrl(),
			'URL_SETTINGS' => $app->getSettingsUrl(),
			'VERSION' => $app->getVersion(),
			'SCOPE' => implode(',', $app->getScope() ?? []),
			'STATUS' => match ($app->getStatus()) {
				ApplicationStatus::Local => 'L',
				ApplicationStatus::Free => 'F',
				ApplicationStatus::Paid => 'P',
				ApplicationStatus::Demo => 'D',
				ApplicationStatus::Trial => 'T',
				ApplicationStatus::Subscription => 'S',
				ApplicationStatus::Unknown => '',
				null => null,
			},
			'DATE_CREATE' => $app->getDateCreate(),
			'DATE_INSTALL' => $app->getDateInstall(),
			'DATE_FINISH' => $app->getDateFinish(),
			'IS_TRIALED' => self::legacyFlag($app->isTrialed()),
			'SHARED_KEY' => $app->getSharedKey(),
			'CLIENT_SECRET' => $app->getClientSecret(),
			'APP_NAME' => $app->getName(),
			'ACCESS' => $app->getAccess(),
			'APPLICATION_TOKEN' => $app->getApplicationToken(),
			'MOBILE' => self::legacyFlag($app->isMobile()),
			'USER_INSTALL' => self::legacyFlag($app->isUserInstall()),
			'INSTALL_COMPLETION_MODE' => $app->getInstallCompletionMode(),
		];
	}

	private static function legacyFlag(?bool $value): ?string
	{
		return $value === null ? null : ($value ? 'Y' : 'N');
	}
}
