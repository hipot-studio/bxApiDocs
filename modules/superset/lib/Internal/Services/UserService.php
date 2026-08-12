<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\Uri;
use Bitrix\Superset\Internal\Api\Dashboard;
use Bitrix\Superset\Internal\Api\Security\Roles;
use Bitrix\Superset\Internal\Api\Security\Users;
use Bitrix\Superset\Internal\Entities\User;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Dto;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;
use Bitrix\Superset\Internal\Repositories\LocalUserRepository;
use Bitrix\Superset\Internal\Jwt;
use Bitrix\Superset\Internal\RequestResult;

final class UserService extends AbstractSupersetContext
{
	private const BX_ROLE_WRITER_NAME = 'writer';
	private const BX_ROLE_READER_NAME = 'reader';
	private const BX_ROLE_EMPTY_NAME = 'empty';

	private const SS_READ_ROLE_NAME = 'bx_read';
	private const SS_WRITE_ROLE_NAME = 'bx_write';
	private const SS_SQL_ROLE_NAME = 'bx_sql';
	private const SS_DATASOURCE_ROLE_NAME = 'bx_datasource';
	private const SS_DATABASE_TRINO_ROLE_NAME = 'bx_database_trino';
	private const SS_PUBLIC_ROLE_NAME = 'Public';

	public function create(array $fields): Main\Result
	{
		$userName = mb_substr((string)($fields['username'] ?? ''), 0, 64);
		if ($userName === '')
		{
			return $this->createErrorResult('username is required', null, HttpStatus::BAD_REQUEST);
		}

		$roles = [
			self::SS_READ_ROLE_NAME,
			self::SS_WRITE_ROLE_NAME,
			self::SS_SQL_ROLE_NAME,
			self::SS_DATASOURCE_ROLE_NAME,
			self::SS_DATABASE_TRINO_ROLE_NAME,
		];

		$password = Random::getString(10, true);
		$userDto = new Dto\Api\Security\User(
			userName: $userName,
			firstName: (string)($fields['first_name'] ?? ''),
			lastName: (string)($fields['last_name'] ?? ''),
			email: (string)($fields['email'] ?? ''),
			password: $password,
		);
		$userDto->roles = $this->getRolesByName($roles);

		$requestResult = $this->getUsersApi()->createUser([
			'active' => $userDto->active,
			'email' => $userDto->email,
			'username' => $userDto->userName,
			'first_name' => $userDto->firstName,
			'last_name' => $userDto->lastName,
			'password' => $userDto->password,
			'roles' => $userDto->roles,
		]);

		if (
			$requestResult->isSuccess()
			&& $requestResult->getHttpStatus() === HttpStatus::CREATED
		)
		{
			$decoded = $this->decode($requestResult->getAnswer());
			if (!is_array($decoded))
			{
				return $this->createErrorResult(
					"Json decode error. Answer: {$requestResult->getAnswer()}",
					$requestResult
				);
			}

			$userDto->id = (int)($decoded['id'] ?? 0);
		}
		elseif ($requestResult->getHttpStatus() === HttpStatus::UNPROCESSABLE_ENTITY)
		{
			$existingUserResult = $this->getUserByName($userDto->userName);
			if (!$existingUserResult->isSuccess())
			{
				return $existingUserResult;
			}

			if (!empty($existingUserResult->getData()['superset_user']))
			{
				return $this->reSaveUser($existingUserResult, $userDto);
			}

			return $this->createRequestErrorResult($requestResult, 'Creating superset user');
		}
		else
		{
			return $this->createRequestErrorResult($requestResult, 'Creating superset user');
		}

		$saveResult = $this->getLocalUserRepository()->saveUser(
			$this->server,
			$userDto->userName,
			$userDto->password,
			$userDto->id,
			md5(uniqid($this->server->getHost() . $userDto->userName . $userDto->id, true))
		);
		if (!$saveResult->isSuccess())
		{
			$result = new Main\Result();
			$result->addErrors($saveResult->getErrors());

			return $result;
		}

		/** @var User $user */
		$user = $saveResult->getData()['user'];

		$result = new Main\Result();
		$result->setData([
			'user' => $user,
			'client_id' => $user->getClientId(),
		]);

		return $result;
	}

	public function update(User $user, array $fields): Main\Result
	{
		$requestResult = $this->getUsersApi()->updateUser(
			$user->getExternalId(),
			[
				'first_name' => (string)($fields['first_name'] ?? ''),
				'last_name' => (string)($fields['last_name'] ?? ''),
			]
		);
		if (
			!$requestResult->isSuccess()
			|| $requestResult->getHttpStatus() !== HttpStatus::OK
		)
		{
			return $this->createRequestErrorResult($requestResult, 'Updating superset user');
		}

		$result = new Main\Result();
		$result->setData([
			'first_name' => (string)($fields['first_name'] ?? ''),
			'last_name' => (string)($fields['last_name'] ?? ''),
		]);

		return $result;
	}

	public function activate(User $user): Main\Result
	{
		return $this->changeActivity($user, true);
	}

	public function deactivate(User $user): Main\Result
	{
		return $this->changeActivity($user, false);
	}

	public function setEmptyRole(User $user): Main\Result
	{
		$requestResult = $this->updateRoles($user, [self::SS_PUBLIC_ROLE_NAME]);
		if (
			!$requestResult->isSuccess()
			|| $requestResult->getHttpStatus() !== HttpStatus::OK
		)
		{
			return $this->createRequestErrorResult($requestResult, 'Set empty role');
		}

		return new Main\Result();
	}

	public function getLoginUrl(User $user): Main\Result
	{
		$editUrl = new Uri($this->connector->buildRequestUrl('/login/'));
		$editUrl->addParams([
			'token' => Jwt::encode(
				[
					'username' => $user->getLogin(),
					'host' => $this->server->getHost(),
				],
				$this->server->getJwtSecret(),
			),
		]);

		$result = new Main\Result();
		$result->setData([
			'url' => $editUrl->getLocator(),
		]);

		return $result;
	}

	public function syncProfile(User $user, array $fields): Main\Result
	{
		$role = (string)($fields['role'] ?? '');
		if ($role === '')
		{
			return $this->createErrorResult('role not found', null, HttpStatus::BAD_REQUEST);
		}

		$roles = self::getSupersetRolesByBitrixRole($role);
		if (empty($roles))
		{
			return $this->createErrorResult('role not found', null, HttpStatus::BAD_REQUEST);
		}

		$updateRolesResult = $this->updateRoles($user, $roles);
		if (
			!$updateRolesResult->isSuccess()
			|| $updateRolesResult->getHttpStatus() !== HttpStatus::OK
		)
		{
			return $this->createRequestErrorResult($updateRolesResult, 'Sync superset user profile');
		}

		$dashboardOwnersResult = $this->getDashboardOwners(
			array_map('intval', $fields['dashboardAllIdList'] ?? [])
		);
		if (!$dashboardOwnersResult->isSuccess())
		{
			return $dashboardOwnersResult;
		}

		$dashboardIdList = array_map('intval', $fields['dashboardIdList'] ?? []);
		$preparedDashboards = $dashboardOwnersResult->getData()['dashboardOwners'] ?? [];
		if (!empty($preparedDashboards))
		{
			$dashboardApi = $this->getDashboardApi();
			foreach ($preparedDashboards as $id => $dashboardData)
			{
				$owners = $dashboardData['owners'];

				if (count($owners) === 1 && current($owners) === 1)
				{
					continue;
				}

				if (in_array($id, $dashboardIdList, true))
				{
					if (in_array($user->getExternalId(), $owners, true))
					{
						continue;
					}

					$owners[] = $user->getExternalId();
				}
				else
				{
					$key = array_search($user->getExternalId(), $owners, true);
					if ($key !== false)
					{
						unset($owners[$key]);
					}
				}

				$owners = array_unique($owners);
				if (count($owners) === count($dashboardData['owners']))
				{
					continue;
				}

				sort($owners);
				$requestResult = $dashboardApi->setDashboardOwners((int)$id, $owners);
				if (
					!$requestResult->isSuccess()
					|| $requestResult->getHttpStatus() !== HttpStatus::OK
				)
				{
					return $this->createRequestErrorResult($requestResult, 'Changing dashboard owner');
				}
			}
		}

		return new Main\Result();
	}

	public function getSupersetUserById(int $id): Main\Result
	{
		$requestResult = $this->getUsersApi()->getUserById($id);
		if (
			!$requestResult->isSuccess()
			|| $requestResult->getHttpStatus() !== HttpStatus::OK
		)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting user by id');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded) || !is_array($decoded['result'] ?? null))
		{
			return $this->createErrorResult('Invalid superset user response');
		}

		$result = new Main\Result();
		$result->setData([
			'user' => $decoded['result'],
		]);

		return $result;
	}

	public function getSupersetUserByName(string $name): Main\Result
	{
		$userResult = $this->getUserByName($name);
		if (!$userResult->isSuccess())
		{
			return $userResult;
		}

		$supersetUser = $userResult->getData()['superset_user'] ?? null;
		if (!is_array($supersetUser))
		{
			return $this->createErrorResult("User '{$name}' not found", null, HttpStatus::NOT_FOUND);
		}

		$result = new Main\Result();
		$result->setData([
			'user' => $supersetUser,
			'local_user' => $userResult->getData()['user'] ?? null,
		]);

		return $result;
	}

	public function getLocalUserByLogin(string $userName): Main\Result
	{
		$result = new Main\Result();
		$result->setData([
			'user' => $this->getLocalUserRepository()->findByLogin($this->server, $userName),
		]);

		return $result;
	}

	public function getLocalUserByClientId(string $clientId): Main\Result
	{
		$result = new Main\Result();
		$result->setData([
			'user' => $this->getLocalUserRepository()->findByClientId($this->server, $clientId),
		]);

		return $result;
	}

	public function getLocalExternalIds(array $excludedLogins = []): Main\Result
	{
		$externalIds = $this->getLocalUserRepository()->getExternalIds($this->server, $excludedLogins);
		sort($externalIds);

		$result = new Main\Result();
		$result->setData([
			'externalIds' => $externalIds,
		]);

		return $result;
	}

	public function getRemoteIds(int $pageSize = 100): Main\Result
	{
		$remoteIds = [];
		$page = 0;
		do
		{
			$requestResult = $this->getUsersApi()->getUsers($page, $pageSize);
			if ($requestResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($requestResult, 'Getting user ids');
			}

			$decoded = $this->decode($requestResult->getAnswer());
			if (!is_array($decoded))
			{
				return $this->createErrorResult('Invalid superset user ids response');
			}

			$currentIds = array_map('intval', $decoded['ids'] ?? []);
			if (!empty($currentIds))
			{
				$remoteIds = array_merge($remoteIds, $currentIds);
			}

			$isRepeatRequest = !empty($currentIds);
			$page++;
		}
		while ($isRepeatRequest);

		$remoteIds = array_values(array_unique($remoteIds));
		sort($remoteIds);

		$result = new Main\Result();
		$result->setData([
			'ids' => $remoteIds,
		]);

		return $result;
	}

	public function mapExternalIdsByClientIds(array $clientIds): Main\Result
	{
		$result = new Main\Result();
		$result->setData([
			'externalIds' => array_map(
				static fn($externalId) => (int)$externalId,
				$this->getLocalUserRepository()->mapExternalIdsByClientIds($this->server, $clientIds),
			),
		]);

		return $result;
	}

	private function getUserByName(string $userName): Main\Result
	{
		$requestResult = $this->getUsersApi()->getUserByName($userName);
		if (
			!$requestResult->isSuccess()
			|| $requestResult->getHttpStatus() !== HttpStatus::OK
		)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting user by name');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		$supersetUser = is_array($decoded) ? current($decoded['result'] ?? []) : null;

		$result = new Main\Result();
		$result->setData([
			'superset_user' => is_array($supersetUser) ? $supersetUser : null,
			'user' => $this->getLocalUserRepository()->findByLogin($this->server, $userName),
		]);

		return $result;
	}

	private function reSaveUser(Main\Result $result, Dto\Api\Security\User $userDto): Main\Result
	{
		$data = $result->getData();

		if (!empty($data['user']) && !empty($data['superset_user']))
		{
			/** @var User $entityUser */
			$entityUser = $data['user'];
			if (empty($entityUser->getClientId()))
			{
				$entityUser->setClientId(
					md5(uniqid($this->server->getHost() . $entityUser->getLogin() . $entityUser->getExternalId(), true))
				);
				$saveResult = $this->getLocalUserRepository()->save($entityUser);
				if (!$saveResult->isSuccess())
				{
					$failedResult = new Main\Result();
					$failedResult->addErrors($saveResult->getErrors());

					return $failedResult;
				}
			}

			$preparedResult = new Main\Result();
			$preparedResult->setData([
				'user' => $entityUser,
				'client_id' => $entityUser->getClientId(),
			]);

			return $preparedResult;
		}

		$existingSupersetUser = $data['superset_user'];
		$userDto->id = (int)$existingSupersetUser['id'];

		$requestResult = $this->getUsersApi()->updateUser(
			$userDto->id,
			['password' => $userDto->password]
		);
		if (
			!$requestResult->isSuccess()
			|| $requestResult->getHttpStatus() !== HttpStatus::OK
		)
		{
			return $this->createRequestErrorResult($requestResult, 'Updating user password');
		}

		$saveResult = $this->getLocalUserRepository()->saveUser(
			$this->server,
			$userDto->userName,
			$userDto->password,
			$userDto->id,
			md5(uniqid($this->server->getHost() . $userDto->userName . $userDto->id, true))
		);
		if (!$saveResult->isSuccess())
		{
			$failedResult = new Main\Result();
			$failedResult->addErrors($saveResult->getErrors());

			return $failedResult;
		}

		/** @var User $user */
		$user = $saveResult->getData()['user'];

		$preparedResult = new Main\Result();
		$preparedResult->setData([
			'user' => $user,
			'client_id' => $user->getClientId(),
		]);

		return $preparedResult;
	}

	private function changeActivity(User $user, bool $active): Main\Result
	{
		$requestResult = $this->getUsersApi()->updateUser(
			$user->getExternalId(),
			[
				'active' => $active,
			]
		);
		if (
			!$requestResult->isSuccess()
			|| $requestResult->getHttpStatus() !== HttpStatus::OK
		)
		{
			return $this->createRequestErrorResult($requestResult, 'Updating superset user activity');
		}

		$result = new Main\Result();
		$result->setData([
			'active' => $active,
		]);

		return $result;
	}

	private function updateRoles(User $user, array $roles): RequestResult
	{
		return $this->getUsersApi()->updateUser(
			$user->getExternalId(),
			[
				'roles' => $this->getRolesByName($roles),
			]
		);
	}

	private function getRolesByName(array $rolesName): array
	{
		$requestResult = $this->getRolesApi()->getRoles(pageSize: 100);
		if (
			!$requestResult->isSuccess()
			|| $requestResult->getHttpStatus() !== HttpStatus::OK
		)
		{
			return [];
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded))
		{
			return [];
		}

		$roleIds = [];
		foreach (($decoded['result'] ?? []) as $role)
		{
			if (is_array($role) && in_array($role['name'] ?? '', $rolesName, true))
			{
				$roleIds[] = (int)$role['id'];
			}
		}

		return $roleIds;
	}

	private static function getSupersetRolesByBitrixRole(string $role): array
	{
		if ($role === self::BX_ROLE_WRITER_NAME)
		{
			return [
				self::SS_READ_ROLE_NAME,
				self::SS_WRITE_ROLE_NAME,
				self::SS_SQL_ROLE_NAME,
				self::SS_DATASOURCE_ROLE_NAME,
				self::SS_DATABASE_TRINO_ROLE_NAME,
			];
		}

		if ($role === self::BX_ROLE_READER_NAME)
		{
			return [
				self::SS_READ_ROLE_NAME,
			];
		}

		if ($role === self::BX_ROLE_EMPTY_NAME)
		{
			return [
				self::SS_PUBLIC_ROLE_NAME,
			];
		}

		return [];
	}

	private function getDashboardOwners(array $filterIdList): Main\Result
	{
		$preparedDashboards = [];
		$page = 0;
		$dashboardApi = $this->getDashboardApi();

		do
		{
			$requestResult = $dashboardApi->getDashboards([], $page, 100);
			if ($requestResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($requestResult, 'Sync superset user. Getting dashboard list');
			}

			$dashboards = $this->decode($requestResult->getAnswer());
			if (!is_array($dashboards))
			{
				return $this->createErrorResult('Invalid dashboard owners response');
			}

			foreach (($dashboards['result'] ?? []) as $dashboardResult)
			{
				if (!is_array($dashboardResult) || !isset($dashboardResult['id']))
				{
					continue;
				}

				$preparedDashboards[(int)$dashboardResult['id']] = [
					'owners' => array_map('intval', array_column($dashboardResult['owners'] ?? [], 'id')),
				];
			}

			$isRepeatRequest = count($dashboards['ids'] ?? []) > 0;
			$page++;
		}
		while ($isRepeatRequest);

		if (!empty($filterIdList))
		{
			$preparedDashboards = array_intersect_key($preparedDashboards, array_flip($filterIdList));
		}

		$result = new Main\Result();
		$result->setData([
			'dashboardOwners' => $preparedDashboards,
		]);

		return $result;
	}

	private function getUsersApi(): Users
	{
		return new Users($this->connector);
	}

	private function getLocalUserRepository(): LocalUserRepository
	{
		return new LocalUserRepository();
	}

	private function getRolesApi(): Roles
	{
		return new Roles($this->connector);
	}

	private function getDashboardApi(): Dashboard
	{
		return new Dashboard($this->connector);
	}
}
