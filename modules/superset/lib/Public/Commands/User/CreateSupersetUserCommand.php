<?php

namespace Bitrix\Superset\Public\Commands\User;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\UserService;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class CreateSupersetUserCommand extends AbstractUserCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly array $fields,
	)
	{
	}

	protected function execute(): Result
	{
		$server = $this->resolveServer($this->server);

		return $this->wrapCreatedUserResult(
			$this->appendUserReference(
				$server,
				(new UserService($server))->create($this->fields),
			),
		);
	}
}
