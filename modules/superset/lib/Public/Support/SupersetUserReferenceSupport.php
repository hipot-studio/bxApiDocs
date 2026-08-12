<?php

namespace Bitrix\Superset\Public\Support;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Entities\User;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Repositories\LocalUserRepository;
use Bitrix\Superset\Internal\Support\SupersetResultFactory;
use Bitrix\Superset\Public\Dto\SupersetUserReferenceDto;

final class SupersetUserReferenceSupport
{
	public function __construct(
		private readonly Server $server,
		private readonly SupersetResultFactory $resultFactory
	)
	{
	}

	public function appendUserReference(Result $result): Result
	{
		$user = $result->getData()['user'] ?? null;
		if (!$user instanceof User)
		{
			return $result;
		}

		$data = $result->getData();
		$data['user_reference'] = $this->getDtoFactory()->createReference($user);
		$result->setData($data);

		return $result;
	}

	public function createUserNotFoundResult(SupersetUserReferenceDto $userReference): Result
	{
		$clientId = $userReference->getClientId();
		$externalId = $userReference->getExternalId();
		$login = $userReference->getLogin();

		if ($clientId !== '')
		{
			return $this->resultFactory->createErrorResult(
				"Superset user with client_id {$clientId} not found",
				null,
				HttpStatus::NOT_FOUND
			);
		}

		if ($externalId > 0)
		{
			return $this->resultFactory->createErrorResult(
				"Superset user with external_id {$externalId} not found",
				null,
				HttpStatus::NOT_FOUND
			);
		}

		if ($login !== '')
		{
			return $this->resultFactory->createErrorResult(
				"Superset user with login {$login} not found",
				null,
				HttpStatus::NOT_FOUND
			);
		}

		return $this->resultFactory->createErrorResult(
			'Superset user reference is empty',
			null,
			HttpStatus::BAD_REQUEST
		);
	}

	public function resolveLocalUser(SupersetUserReferenceDto $userReference): ?User
	{
		$repository = new LocalUserRepository();

		if ($userReference->getClientId() !== '')
		{
			return $repository->findByClientId($this->server, $userReference->getClientId());
		}

		if ($userReference->getExternalId() > 0)
		{
			return $repository->findByExternalId($this->server, $userReference->getExternalId());
		}

		if ($userReference->getLogin() !== '')
		{
			return $repository->findByLogin($this->server, $userReference->getLogin());
		}

		return null;
	}

	private function getDtoFactory(): UserDtoFactory
	{
		return new UserDtoFactory();
	}
}
