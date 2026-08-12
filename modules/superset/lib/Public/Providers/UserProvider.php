<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Entities\User;
use Bitrix\Superset\Internal\Services\UserService;
use Bitrix\Superset\Public\Dto\SupersetUserReferenceDto;
use Bitrix\Superset\Public\Support\AbstractPublicEntryPoint;
use Bitrix\Superset\Public\Support\SupersetUserReferenceSupport;
use Bitrix\Superset\Public\Support\UserDtoFactory;

final class UserProvider extends AbstractPublicEntryPoint
{
	public function getById(int $id): Result
	{
		return $this->getService()->getSupersetUserById($id);
	}

	public function getByName(string $name): Result
	{
		$result = $this->getService()->getSupersetUserByName($name);
		$localUser = $result->getData()['local_user'] ?? null;
		if ($localUser instanceof User)
		{
			$data = $result->getData();
			$data['local_user'] = $this->getDtoFactory()->createRuntime($localUser);
			$result->setData($data);
		}

		return $result;
	}

	public function getLocalByLogin(string $name): Result
	{
		return $this->wrapLocalUserResult(
			$this->getUserReferenceSupport()->appendUserReference($this->getService()->getLocalUserByLogin($name))
		);
	}

	public function getLocalByClientId(string $clientId): Result
	{
		return $this->wrapLocalUserResult(
			$this->getUserReferenceSupport()->appendUserReference(
				$this->getService()->getLocalUserByClientId($clientId)
			)
		);
	}

	public function getLocalExternalIds(array $excludedLogins = []): Result
	{
		return $this->getService()->getLocalExternalIds($excludedLogins);
	}

	public function getRemoteIds(int $pageSize = 100): Result
	{
		return $this->getService()->getRemoteIds($pageSize);
	}

	public function mapExternalIdsByClientIds(array $clientIds): Result
	{
		return $this->getService()->mapExternalIdsByClientIds($clientIds);
	}

	public function getLoginUrl(SupersetUserReferenceDto $user): Result
	{
		$localUser = $this->getUserReferenceSupport()->resolveLocalUser($user);
		if (!$localUser)
		{
			return $this->getUserReferenceSupport()->createUserNotFoundResult($user);
		}

		return $this->getService()->getLoginUrl($localUser);
	}

	private function getUserReferenceSupport(): SupersetUserReferenceSupport
	{
		return new SupersetUserReferenceSupport($this->server, $this->getResultFactory());
	}

	private function getService(): UserService
	{
		return new UserService($this->server, $this->connector);
	}

	private function wrapLocalUserResult(Result $result): Result
	{
		$user = $result->getData()['user'] ?? null;
		if (!$user instanceof User)
		{
			return $result;
		}

		$data = $result->getData();
		$data['user'] = $this->getDtoFactory()->createRuntime($user);
		$result->setData($data);

		return $result;
	}

	private function getDtoFactory(): UserDtoFactory
	{
		return new UserDtoFactory();
	}
}
