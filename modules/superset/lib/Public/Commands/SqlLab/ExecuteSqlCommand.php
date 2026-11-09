<?php

namespace Bitrix\Superset\Public\Commands\SqlLab;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\SqlLabService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;
use Bitrix\Superset\Public\Dto\SupersetUserReferenceDto;

final class ExecuteSqlCommand extends AbstractServerCommand
{
	/**
	 * @param ServerReferenceDto $server
	 * @param string $sql Raw SQL SELECT to execute through SQL Lab.
	 * @param int|null $limit Optional row cap (queryLimit).
	 * @param SupersetUserReferenceDto $user Caller identity: requires an existing Superset account,
	 *        keys the rate limiter and the audit trail.
	 * @param bool $useActAs Whether the query runs in Superset as this user (act-as), so the user's roles,
	 *        RLS and table permissions apply; a Superset that does not confirm the substitution makes the
	 *        command fail. Without it the query runs under the service account and the user only keys
	 *        the limiter and the audit.
	 */
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly string $sql,
		public readonly ?int $limit,
		public readonly SupersetUserReferenceDto $user,
		public readonly bool $useActAs = false,
	)
	{
	}

	protected function execute(): Result
	{
		return (new SqlLabService($this->resolveServer($this->server)))->execute(
			$this->sql,
			$this->limit,
			$this->useActAs ? $this->user->getLogin() : null,
		);
	}
}
