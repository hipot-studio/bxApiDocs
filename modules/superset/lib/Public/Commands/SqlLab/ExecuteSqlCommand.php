<?php

namespace Bitrix\Superset\Public\Commands\SqlLab;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\SqlLabService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class ExecuteSqlCommand extends AbstractServerCommand
{
	/**
	 * @param ServerReferenceDto $server
	 * @param string $sql Raw SQL SELECT to execute through SQL Lab.
	 * @param int|null $limit Optional row cap (queryLimit).
	 */
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly string $sql,
		public readonly ?int $limit = null,
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
