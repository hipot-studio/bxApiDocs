<?php

namespace Bitrix\Superset\Internal\Repositories;

use Bitrix\Superset\Internal\Entities\Server;

final class SelfHostedServerRepository
{
	private const DEFAULT_ACCOUNT_ID = 1;

	public function __construct(private ?LocalServerRepository $repository = null)
	{
		$this->repository ??= new LocalServerRepository();
	}

	public function find(): ?Server
	{
		return $this->repository->findLatestByAccountId(self::DEFAULT_ACCOUNT_ID);
	}

	public function getOrCreate(): Server
	{
		return $this->find() ?? $this->repository->create([
			'accountId' => self::DEFAULT_ACCOUNT_ID,
		]);
	}
}
