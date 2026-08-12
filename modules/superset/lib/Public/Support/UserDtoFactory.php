<?php

namespace Bitrix\Superset\Public\Support;

use Bitrix\Superset\Internal\Entities\User;
use Bitrix\Superset\Public\Dto\SupersetUserReferenceDto;
use Bitrix\Superset\Public\Dto\SupersetUserRuntimeDto;

final class UserDtoFactory
{
	public function createReference(User $user): SupersetUserReferenceDto
	{
		return new SupersetUserReferenceDto(
			externalId: (int)$user->getExternalId(),
			login: (string)$user->getLogin(),
			clientId: (string)$user->getClientId(),
		);
	}

	public function createRuntime(User $user): SupersetUserRuntimeDto
	{
		return new SupersetUserRuntimeDto(
			externalId: (int)$user->getExternalId(),
			login: (string)$user->getLogin(),
			clientId: (string)$user->getClientId(),
			accessPassword: (string)($user->getAccessPassword() ?? ''),
		);
	}
}
