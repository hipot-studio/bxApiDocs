<?php

namespace Bitrix\Superset\Internal\Repositories;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Entities\User;
use Bitrix\Superset\Internal\Models\EO_User;
use Bitrix\Superset\Internal\Models\UserTable;
use Bitrix\Superset\Internal\Repositories\Mapper\UserOrmMapper;

final class LocalUserRepository
{
	public function __construct(private ?UserOrmMapper $mapper = null)
	{
		$this->mapper ??= new UserOrmMapper();
	}

	public function findByLogin(Server $server, string $userName): ?User
	{
		return $this->findOne($server, [
			'=LOGIN' => $userName,
		]);
	}

	public function findByClientId(Server $server, string $clientId): ?User
	{
		if ($clientId === '')
		{
			return null;
		}

		return $this->findOne($server, [
			'=CLIENT_ID' => $clientId,
		]);
	}

	public function findByExternalId(Server $server, int $externalId): ?User
	{
		return $this->findOne($server, [
			'=EXTERNAL_ID' => $externalId,
		]);
	}

	public function mapClientIdsByExternalIds(Server $server, array $externalIds): array
	{
		if (empty($externalIds))
		{
			return [];
		}

		$clientIds = UserTable::getList([
			'select' => ['EXTERNAL_ID', 'CLIENT_ID'],
			'filter' => [
				'=EXTERNAL_ID' => $externalIds,
				'=SERVER_ID' => $server->getId(),
			],
		])->fetchAll();

		return array_column($clientIds, 'CLIENT_ID', 'EXTERNAL_ID');
	}

	public function mapExternalIdsByClientIds(Server $server, array $clientIds): array
	{
		if (empty($clientIds))
		{
			return [];
		}

		$externalIds = UserTable::getList([
			'select' => ['EXTERNAL_ID', 'CLIENT_ID'],
			'filter' => [
				'=CLIENT_ID' => $clientIds,
				'=SERVER_ID' => $server->getId(),
			],
		])->fetchAll();

		return array_column($externalIds, 'EXTERNAL_ID', 'CLIENT_ID');
	}

	public function getExternalIds(Server $server, array $excludedLogins = []): array
	{
		$filter = [
			'=SERVER_ID' => $server->getId(),
		];

		if ($excludedLogins)
		{
			$filter['!=LOGIN'] = $excludedLogins;
		}

		$users = UserTable::getList([
			'select' => ['EXTERNAL_ID'],
			'filter' => $filter,
		])->fetchAll();

		return array_map('intval', array_column($users, 'EXTERNAL_ID'));
	}

	public function save(User $user): Result
	{
		$ormUser = $this->mapper->convertToOrm($user);
		$saveResult = $ormUser->save();
		if (!$saveResult->isSuccess())
		{
			return $saveResult;
		}

		$this->synchronizeEntity($user, $this->mapper->convertFromOrm($ormUser));

		return $saveResult;
	}

	public function saveUser(
		Server $server,
		string $userName,
		string $password,
		int $externalId,
		string $clientId,
	): Result
	{
		$user = (new User())
			->setServerId((int)$server->getId())
			->setLogin($userName)
			->setAccessPassword($password)
			->setExternalId($externalId)
			->setClientId($clientId);

		$saveResult = $this->save($user);
		if (!$saveResult->isSuccess())
		{
			return $saveResult;
		}

		$saveResult->setData([
			'user' => $user,
		]);

		return $saveResult;
	}

	private function findOne(Server $server, array $filter): ?User
	{
		$ormUser = UserTable::getList([
			'select' => ['ID', 'EXTERNAL_ID', 'CLIENT_ID', 'LOGIN', 'ACCESS_PASSWORD', 'SERVER_ID', 'CREATED', 'UPDATED'],
			'filter' => [
				'=SERVER_ID' => $server->getId(),
				...$filter,
			],
			'limit' => 1,
		])->fetchObject();

		return $ormUser instanceof EO_User ? $this->mapper->convertFromOrm($ormUser) : null;
	}

	private function synchronizeEntity(User $target, User $source): void
	{
		$target
			->setId($source->getId())
			->setLogin($source->getLogin())
			->setAccessPassword($source->getAccessPassword())
			->setServerId($source->getServerId())
			->setCreated($source->getCreated())
			->setUpdated($source->getUpdated())
			->setExternalId($source->getExternalId())
			->setClientId($source->getClientId());
	}
}
