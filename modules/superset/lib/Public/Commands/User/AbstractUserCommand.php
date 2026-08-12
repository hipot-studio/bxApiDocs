<?php

namespace Bitrix\Superset\Public\Commands\User;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Entities\User;
use Bitrix\Superset\Internal\Support\SupersetResultFactory;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\SupersetUserReferenceDto;
use Bitrix\Superset\Public\Support\SupersetUserReferenceSupport;
use Bitrix\Superset\Public\Support\UserDtoFactory;

abstract class AbstractUserCommand extends AbstractServerCommand
{
	final protected function appendUserReference(Server $server, Result $result): Result
	{
		return $this->getUserReferenceSupport($server)->appendUserReference($result);
	}

	final protected function resolveLocalUser(Server $server, SupersetUserReferenceDto $user): ?User
	{
		return $this->getUserReferenceSupport($server)->resolveLocalUser($user);
	}

	final protected function createUserNotFoundResult(Server $server, SupersetUserReferenceDto $user): Result
	{
		return $this->getUserReferenceSupport($server)->createUserNotFoundResult($user);
	}

	final protected function wrapCreatedUserResult(Result $result): Result
	{
		$user = $result->getData()['user'] ?? null;
		if (!$user instanceof User)
		{
			return $result;
		}

		$data = $result->getData();
		$data['user'] = (new UserDtoFactory())->createRuntime($user);
		$result->setData($data);

		return $result;
	}

	private function getUserReferenceSupport(Server $server): SupersetUserReferenceSupport
	{
		return new SupersetUserReferenceSupport($server, new SupersetResultFactory());
	}
}
