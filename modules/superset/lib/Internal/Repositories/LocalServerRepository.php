<?php

namespace Bitrix\Superset\Internal\Repositories;

use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Models\EO_Server;
use Bitrix\Superset\Internal\Models\EO_User;
use Bitrix\Superset\Internal\Models\ServerTable;
use Bitrix\Superset\Internal\Models\UserTable;
use Bitrix\Superset\Internal\Repositories\Mapper\ServerOrmMapper;

final class LocalServerRepository
{
	private const NORMALIZED_DATE_TIME_FORMAT = 'Y-m-d H:i:s';

	public function __construct(private ?ServerOrmMapper $mapper = null)
	{
		$this->mapper ??= new ServerOrmMapper();
	}

	public function findById(int $serverId): ?Server
	{
		return $this->findOne([
			'=ID' => $serverId,
		]);
	}

	public function findByPortalId(string $portalId): ?Server
	{
		return $this->findOne([
			'=PORTAL_ID' => $portalId,
		]);
	}

	public function findVerifiedByPortalId(string $portalId): ?Server
	{
		return $this->findOne([
			'=PORTAL_ID' => $portalId,
			'=IS_PORTAL_ID_VERIFIED' => 'Y',
		]);
	}

	public function findByAccountId(int $accountId): ?Server
	{
		return $this->findOne([
			'=ACCOUNT_ID' => $accountId,
		]);
	}

	public function findLatestByAccountId(int $accountId): ?Server
	{
		return $this->findOne(
			[
				'=ACCOUNT_ID' => $accountId,
			],
			[
				'ID' => 'DESC',
			]
		);
	}

	public function findVerifiedByAccountId(int $accountId): ?Server
	{
		return $this->findOne([
			'=ACCOUNT_ID' => $accountId,
			'=IS_PORTAL_ID_VERIFIED' => 'Y',
		]);
	}

	public function findUnverifiedByAccountId(int $accountId): ?Server
	{
		return $this->findOne([
			'=ACCOUNT_ID' => $accountId,
			'=IS_PORTAL_ID_VERIFIED' => 'N',
		]);
	}

	public function findByAccountIdAndPortalUrl(int $accountId, string $portalUrl): ?Server
	{
		return $this->findOne([
			'=ACCOUNT_ID' => $accountId,
			'=PORTAL_URL' => $portalUrl,
		]);
	}

	public function findByInstanceKey(string $instanceKey): ?Server
	{
		return $this->findOne([
			'=INSTANCE_KEY' => $instanceKey,
		]);
	}

	public function findByInstanceUsername(string $instanceUsername): ?Server
	{
		return $this->findOne([
			'=INSTANCE_USERNAME' => $instanceUsername,
		]);
	}

	public function getByAccountId(int $accountId): array
	{
		return $this->getList([
			'=ACCOUNT_ID' => $accountId,
		]);
	}

	public function getByPortalUrl(string $portalUrl): array
	{
		return $this->getList([
			'=PORTAL_URL' => $portalUrl,
		]);
	}

	public function countAll(): int
	{
		return ServerTable::getCount();
	}

	public function countVerifiedByAccountId(int $accountId): int
	{
		return ServerTable::getCount([
			'=ACCOUNT_ID' => $accountId,
			'=IS_PORTAL_ID_VERIFIED' => 'Y',
		]);
	}

	public function countUnverifiedByAccountId(int $accountId): int
	{
		return ServerTable::getCount([
			'=ACCOUNT_ID' => $accountId,
			'=IS_PORTAL_ID_VERIFIED' => 'N',
		]);
	}

	public function existsByPortalId(string $portalId): bool
	{
		return $this->findByPortalId($portalId) !== null;
	}

	public function existsByInstanceKey(string $instanceKey): bool
	{
		return $this->findByInstanceKey($instanceKey) !== null;
	}

	public function existsByInstanceUsername(string $instanceUsername): bool
	{
		return $this->findByInstanceUsername($instanceUsername) !== null;
	}

	public function create(array $fields): Server
	{
		return $this->applyState(new Server(), $fields);
	}

	public function save(Server $server): Result
	{
		$ormServer = $this->mapper->convertToOrm($server);
		$saveResult = $ormServer->save();
		if (!$saveResult->isSuccess())
		{
			return $saveResult;
		}

		$this->synchronizeEntity($server, $this->mapper->convertFromOrm($ormServer));

		return $saveResult;
	}

	public function update(Server $server, array $fields): Result
	{
		$this->applyState($server, $fields);

		return $this->save($server);
	}

	public function deleteWithUsers(Server $server): Result
	{
		$result = new Result();

		$users = UserTable::getList([
			'filter' => [
				'=SERVER_ID' => $server->getId(),
			],
		])->fetchCollection();

		foreach ($users as $user)
		{
			if (!$user instanceof EO_User)
			{
				continue;
			}

			$deleteResult = $user->delete();
			if (!$deleteResult->isSuccess())
			{
				$result->addErrors($deleteResult->getErrors());
			}
		}

		if (!$result->isSuccess() || $server->getId() === null)
		{
			return $result;
		}

		$deleteServerResult = ServerTable::delete($server->getId());
		if (!$deleteServerResult->isSuccess())
		{
			$result->addErrors($deleteServerResult->getErrors());
		}

		return $result;
	}

	private function findOne(array $filter, array $order = []): ?Server
	{
		$ormServer = ServerTable::getList([
			'select' => ['*'],
			'filter' => $filter,
			'order' => $order,
			'limit' => 1,
		])->fetchObject();

		return $ormServer instanceof EO_Server ? $this->mapper->convertFromOrm($ormServer) : null;
	}

	private function getList(array $filter): array
	{
		$servers = [];
		$ormServers = ServerTable::getList([
			'select' => ['*'],
			'filter' => $filter,
		])->fetchCollection();

		foreach ($ormServers as $ormServer)
		{
			if ($ormServer instanceof EO_Server)
			{
				$servers[] = $this->mapper->convertFromOrm($ormServer);
			}
		}

		return $servers;
	}

	private function applyState(Server $server, array $fields): Server
	{
		if (array_key_exists('accountId', $fields))
		{
			$server->setAccountId((int)$fields['accountId']);
		}

		if (array_key_exists('portalId', $fields))
		{
			$this->applyNullableStringField($server, 'PortalId', $fields['portalId']);
		}

		if (array_key_exists('portalUrl', $fields))
		{
			$this->applyNullableStringField($server, 'PortalUrl', $fields['portalUrl']);
		}

		if (array_key_exists('isPortalIdVerified', $fields))
		{
			$server->setIsPortalIdVerified(
				$this->normalizeVerifiedFlag($fields['isPortalIdVerified'])
			);
		}

		if (!empty($fields['clearInstanceState']))
		{
			$this->clearInstanceState($server);
		}

		if (array_key_exists('instanceKey', $fields))
		{
			$this->applyNullableStringField($server, 'InstanceKey', $fields['instanceKey']);
		}

		if (array_key_exists('instanceUsername', $fields))
		{
			$this->applyNullableStringField($server, 'InstanceUsername', $fields['instanceUsername']);
		}

		if (array_key_exists('dateStartAttempt', $fields))
		{
			$this->applyDateTimeField($server, $fields['dateStartAttempt']);
		}

		if (array_key_exists('startJobId', $fields))
		{
			$this->applyNullableStringField($server, 'StartJobId', $fields['startJobId']);
		}

		if (!empty($fields['clearStartJobId']))
		{
			$server->unsetStartJobId();
		}

		if (array_key_exists('host', $fields))
		{
			$this->applyNullableStringField($server, 'Host', $fields['host']);
		}

		if (array_key_exists('accessPassword', $fields))
		{
			$this->applyNullableStringField($server, 'AccessPassword', $fields['accessPassword']);
		}

		if (array_key_exists('version', $fields))
		{
			if ($fields['version'] === null)
			{
				$server->unsetVersion();
			}
			else
			{
				$server->setVersion((int)$fields['version']);
			}
		}

		if (array_key_exists('token', $fields))
		{
			$this->applyNullableStringField($server, 'Token', $fields['token']);
		}

		if (array_key_exists('refreshToken', $fields))
		{
			$this->applyNullableStringField($server, 'RefreshToken', $fields['refreshToken']);
		}

		if (!empty($fields['clearToken']))
		{
			$server->unsetToken();
		}

		if (!empty($fields['clearRefreshToken']))
		{
			$server->unsetRefreshToken();
		}

		if (array_key_exists('jwtSecret', $fields))
		{
			$this->applyNullableStringField($server, 'JwtSecret', $fields['jwtSecret']);
		}

		return $server;
	}

	private function clearInstanceState(Server $server): void
	{
		$server->unsetHost();
		$server->unsetAccessPassword();
		$server->unsetInstanceKey();
		$server->unsetInstanceUsername();
		$server->unsetToken();
		$server->unsetRefreshToken();
		$server->unsetStartJobId();
		$server->unsetDateStartAttempt();
		$server->unsetVersion();
		$server->unsetJwtSecret();
	}

	private function applyNullableStringField(Server $server, string $fieldName, mixed $value): void
	{
		$setter = "set{$fieldName}";
		$unsetter = "unset{$fieldName}";

		if ($value === null)
		{
			$server->{$unsetter}();

			return;
		}

		$server->{$setter}((string)$value);
	}

	private function applyDateTimeField(Server $server, mixed $value): void
	{
		if ($value === null || $value === '')
		{
			$server->unsetDateStartAttempt();

			return;
		}

		if ($value instanceof DateTime)
		{
			$server->setDateStartAttempt($value);

			return;
		}

		$dateTime = DateTime::tryParse((string)$value, self::NORMALIZED_DATE_TIME_FORMAT) ?? DateTime::tryParse((string)$value);

		$server->setDateStartAttempt($dateTime ?? new DateTime((string)$value));
	}

	private function synchronizeEntity(Server $target, Server $source): void
	{
		$account = $target->getAccount();

		$target
			->setId($source->getId())
			->setHost($source->getHost())
			->setAccessPassword($source->getAccessPassword())
			->setInstanceKey($source->getInstanceKey())
			->setInstanceUsername($source->getInstanceUsername())
			->setToken($source->getToken())
			->setRefreshToken($source->getRefreshToken())
			->setStartJobId($source->getStartJobId())
			->setAccountId($source->getAccountId())
			->setVersion($source->getVersion())
			->setIsPortalIdVerified($source->isPortalIdVerified())
			->setPortalId($source->getPortalId())
			->setPortalUrl($source->getPortalUrl() !== '' ? $source->getPortalUrl() : null)
			->setJwtSecret($source->getJwtSecret())
			->setDateStartAttempt($source->getDateStartAttempt());

		if ($account !== null)
		{
			$target->setAccount($account);
		}
	}

	private function normalizeVerifiedFlag(mixed $value): bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		return in_array($value, ['Y', 'y', 1, '1'], true);
	}
}
