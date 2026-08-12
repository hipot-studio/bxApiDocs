<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Api\Version;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Public\Support\AbstractPublicEntryPoint;

class VersionProvider extends AbstractPublicEntryPoint
{
	private const CACHE_TTL = 86400;
	private const CACHE_ID = 'superset_current_version';

	public function getSupersetVersion(): ?string
	{
		$cacheManager = Application::getInstance()->getManagedCache();
		if ($cacheManager->read(self::CACHE_TTL, self::CACHE_ID))
		{
			return $cacheManager->get(self::CACHE_ID);
		}

		$getVersionResult = $this->fetchSupersetVersion();
		if (!$getVersionResult->isSuccess())
		{
			return null;
		}

		$version = $getVersionResult->getData()['version'];
		$cacheManager->set(self::CACHE_ID, $version);

		return $version;
	}

	private function fetchSupersetVersion(): Result
	{
		$api = new Version($this->connector);
		$requestResult = $api->getVersion();

		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting superset version');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		$version = $decoded['version'] ?? null;
		$result = new Result();
		if (!is_string($version) || $version === '')
		{
			$result->addError(new Error('Version not found in Superset response', 'VERSION_NOT_FOUND'));

			return $result;
		}

		$result->setData(['version' => $version]);

		return $result;
	}

	public static function clearCache(): void
	{
		$cacheManager = Application::getInstance()->getManagedCache();
		$cacheManager->clean(self::CACHE_ID);
	}

	public static function isVersionSatisfied(string $currentVersion, string $requiredVersion): bool
	{
		$current = self::parseVersion($currentVersion);
		$required = self::parseVersion($requiredVersion);

		if ($current === null || $required === null)
		{
			return false;
		}

		if ($current['major'] !== $required['major'])
		{
			return $current['major'] > $required['major'];
		}
		if ($current['minor'] !== $required['minor'])
		{
			return $current['minor'] > $required['minor'];
		}
		if ($current['patch'] !== $required['patch'])
		{
			return $current['patch'] > $required['patch'];
		}

		return $current['build'] >= $required['build'];
	}

	private static function parseVersion(string $version): ?array
	{
		if (!preg_match('/^(\d+)\.(\d+)\.(\d+)(?:_bx(\d+))?$/', $version, $matches))
		{
			return null;
		}

		return [
			'major' => (int)$matches[1],
			'minor' => (int)$matches[2],
			'patch' => (int)$matches[3],
			'build' => isset($matches[4]) ? (int)$matches[4] : 0,
		];
	}
}
