<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Repositories\SelfHostedServerRepository;

final class SelfHostedServerRuntimeService
{
	public function __construct(private ?SelfHostedServerRepository $repository = null)
	{
		$this->repository ??= new SelfHostedServerRepository();
	}

	public function find(): ?Server
	{
		return $this->repository->find();
	}

	public function getOrCreate(): Server
	{
		return $this->repository->getOrCreate();
	}
}
