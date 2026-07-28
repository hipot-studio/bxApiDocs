<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware;

use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorRequest;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\Main\Error;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;
use Bitrix\Superset\Public\Providers\VersionProvider;

class SupersetVersionCheck extends Base
{
	private const ID = 'SUPERSET_VERSION_CHECK';

	private string $requiredVersion;
	private ServerReferenceDto $server;

	public function __construct(ServerReferenceDto $server, string $requiredVersion = '')
	{
		$this->server = $server;
		$this->requiredVersion = $requiredVersion;
	}

	public static function getMiddlewareId(): string
	{
		return self::ID;
	}

	public function beforeRequest(IntegratorRequest $request): ?IntegratorResponse
	{
		if (!$this->requiredVersion)
		{
			return null;
		}

		$currentVersion = (new VersionProvider($this->server))->getSupersetVersion();
		if ($currentVersion === null)
		{
			return null;
		}

		if (SupersetHostMode::isCloud())
		{
			return null;
		}

		if (!VersionProvider::isVersionSatisfied($currentVersion, $this->requiredVersion))
		{
			return new IntegratorResponse(
				IntegratorResponse::STATUS_INNER_ERROR,
				null,
				[new Error(
					"Action {$request->getAction()} requires superset version {$this->requiredVersion}. Current: {$currentVersion}",
					'VERSION_NOT_SUPPORTED'
				)]
			);
		}

		return null;
	}
}
