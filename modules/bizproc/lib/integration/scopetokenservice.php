<?php

namespace Bitrix\Bizproc\Integration;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Random;
use Bitrix\Disk;
use Bitrix\Main\Web\Uri;

/**
 * Manage scope tokens for quick image show
 */
class ScopeTokenService
{
	private const SCOPE_PREFIX = 'BP';
	private const TOKEN_PARAM = '_esd';

	private static string $scope;

	private Disk\QuickAccess\ScopeTokenService $service;

	/**
	 * Set scope string. Common for all next token generations.
	 * @param string $scope
	 * @return void
	 */
	public static function setScope(string $scope): void
	{
		if (!empty($scope))
		{
			self::$scope = self::SCOPE_PREFIX . '_' . mb_strtoupper($scope);
		}
	}

	public function getToken(mixed $file): ?string
	{
		$service = $this->getService();
		if (!isset($service))
		{
			return null;
		}

		$scope = $this->getScope();
		if (!$service->grantAccessToScope($scope))
		{
			return null;
		}

		return $service->getEncryptedScopeForObject($file, $scope);
	}

	public function tokenizeUrl(string $url, mixed $file): ?string
	{
		$token = $this->getToken($file);
		if ($token === null)
		{
			return null;
		}


		$uri = new Uri($url);
		$uri->addParams([self::TOKEN_PARAM => $token]);

		return $uri->getUri();
	}

	private function getService(): ?Disk\QuickAccess\ScopeTokenService
	{
		if (!Loader::includeModule('disk'))
		{
			return null;
		}

		if (!isset($this->service))
		{
			$this->service = ServiceLocator::getInstance()->get('disk.scopeTokenService');
		}

		return $this->service;
	}

	private function getScope(): string
	{
		return self::$scope
			?? (self::SCOPE_PREFIX . '_' . Random::getString(6));
	}
}
