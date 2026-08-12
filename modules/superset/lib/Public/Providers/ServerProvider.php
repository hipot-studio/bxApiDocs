<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Repositories\LocalServerRepository;
use Bitrix\Superset\Internal\Services\ServerAvailabilityService;
use Bitrix\Superset\Public\Dto\ServerRuntimeStateDto;
use Bitrix\Superset\Public\Support\AbstractPublicEntryPoint;
use Bitrix\Superset\Public\Support\ServerDtoFactory;

final class ServerProvider extends AbstractPublicEntryPoint
{
	public function getContext(): Result
	{
		$dtoFactory = $this->getDtoFactory();
		$server = $dtoFactory->createRuntimeState($this->server);
		$result = new Result();
		$result->setData([
			'server' => $server,
			'server_reference' => $dtoFactory->createReference($this->server),
		]);

		return $result;
	}

	public function ping(): Result
	{
		return $this->getAvailabilityService()->ping();
	}

	public static function readJwtPublicKeyById(int $serverId): string
	{
		$server = self::getRepository()->findById($serverId);

		return $server instanceof Server ? self::encodeJwtPublicKey($server) : '';
	}

	public static function findById(int $serverId): ?ServerRuntimeStateDto
	{
		return self::mapServer(self::getRepository()->findById($serverId));
	}

	public static function findByPortalId(string $portalId): ?ServerRuntimeStateDto
	{
		return self::mapServer(self::getRepository()->findByPortalId($portalId));
	}

	public static function findVerifiedByPortalId(string $portalId): ?ServerRuntimeStateDto
	{
		return self::mapServer(self::getRepository()->findVerifiedByPortalId($portalId));
	}

	public static function findByAccountId(int $accountId): ?ServerRuntimeStateDto
	{
		return self::mapServer(self::getRepository()->findByAccountId($accountId));
	}

	public static function findLatestByAccountId(int $accountId): ?ServerRuntimeStateDto
	{
		return self::mapServer(self::getRepository()->findLatestByAccountId($accountId));
	}

	public static function findVerifiedByAccountId(int $accountId): ?ServerRuntimeStateDto
	{
		return self::mapServer(self::getRepository()->findVerifiedByAccountId($accountId));
	}

	public static function findUnverifiedByAccountId(int $accountId): ?ServerRuntimeStateDto
	{
		return self::mapServer(self::getRepository()->findUnverifiedByAccountId($accountId));
	}

	public static function findByInstanceKey(string $instanceKey): ?ServerRuntimeStateDto
	{
		return self::mapServer(self::getRepository()->findByInstanceKey($instanceKey));
	}

	public static function findByAccountIdAndPortalUrl(int $accountId, string $portalUrl): ?ServerRuntimeStateDto
	{
		return self::mapServer(self::getRepository()->findByAccountIdAndPortalUrl($accountId, $portalUrl));
	}

	public static function listByAccountId(int $accountId): array
	{
		return self::mapServerList(self::getRepository()->getByAccountId($accountId));
	}

	public static function listByPortalUrl(string $portalUrl): array
	{
		return self::mapServerList(self::getRepository()->getByPortalUrl($portalUrl));
	}

	public static function countAll(): int
	{
		return self::getRepository()->countAll();
	}

	public static function countVerifiedByAccountId(int $accountId): int
	{
		return self::getRepository()->countVerifiedByAccountId($accountId);
	}

	public static function countUnverifiedByAccountId(int $accountId): int
	{
		return self::getRepository()->countUnverifiedByAccountId($accountId);
	}

	public static function existsByPortalId(string $portalId): bool
	{
		return self::getRepository()->existsByPortalId($portalId);
	}

	public static function existsByInstanceKey(string $instanceKey): bool
	{
		return self::getRepository()->existsByInstanceKey($instanceKey);
	}

	public static function existsByInstanceUsername(string $instanceUsername): bool
	{
		return self::getRepository()->existsByInstanceUsername($instanceUsername);
	}

	private static function getRepository(): LocalServerRepository
	{
		return new LocalServerRepository();
	}

	private function getDtoFactory(): ServerDtoFactory
	{
		return new ServerDtoFactory();
	}

	private function getAvailabilityService(): ServerAvailabilityService
	{
		return new ServerAvailabilityService($this->server, $this->connector);
	}

	private static function mapServer(?Server $server): ?ServerRuntimeStateDto
	{
		return $server ? (new ServerDtoFactory())->createRuntimeState($server) : null;
	}

	private static function mapServerList(array $servers): array
	{
		$result = [];
		$dtoFactory = new ServerDtoFactory();
		foreach ($servers as $server)
		{
			if ($server instanceof Server)
			{
				$result[] = $dtoFactory->createRuntimeState($server);
			}
		}

		return $result;
	}

	private static function encodeJwtPublicKey(Server $server): string
	{
		$jwtSecret = $server->getJwtSecret() ?? '';
		if ($jwtSecret === '')
		{
			return '';
		}

		$privateKey = openssl_pkey_get_private($jwtSecret);
		if ($privateKey === false)
		{
			return '';
		}

		$details = openssl_pkey_get_details($privateKey);

		return base64_encode($details['key'] ?? '');
	}
}
