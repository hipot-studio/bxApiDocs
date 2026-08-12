<?php

namespace Bitrix\Superset\Public\Commands\User;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\UserService;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;
use Bitrix\Superset\Public\Dto\SupersetUserReferenceDto;

final class SyncSupersetUserProfileCommand extends AbstractUserCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly SupersetUserReferenceDto $user,
		public readonly array $fields,
	)
	{
	}

	protected function execute(): Result
	{
		$server = $this->resolveServer($this->server);
		$localUser = $this->resolveLocalUser($server, $this->user);
		if (!$localUser)
		{
			return $this->createUserNotFoundResult($server, $this->user);
		}

		return (new UserService($server))->syncProfile($localUser, $this->fields);
	}
}
