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
	 *        keys the rate limiter and the audit trail. The query itself runs under the Superset
	 *        service token, so the user's Superset roles and RLS are NOT applied. See ADR p. 8.
	 */
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly string $sql,
		public readonly ?int $limit,
		public readonly SupersetUserReferenceDto $user,
	)
	{
	}

	protected function execute(): Result
	{
		return (new SqlLabService($this->resolveServer($this->server)))->execute(
			$this->sql,
			$this->limit,
		);
	}
}
