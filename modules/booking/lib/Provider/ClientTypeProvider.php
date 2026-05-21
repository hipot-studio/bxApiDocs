<?php

declare(strict_types=1);

namespace Bitrix\Booking\Provider;

use Bitrix\Booking\Entity\Client\ClientType;
use Bitrix\Booking\Entity\Client\ClientTypeCollection;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\ClientTypeRepositoryInterface;

class ClientTypeProvider
{
	private ClientTypeRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getClientTypeRepository();
	}

	public function get(string $code, string $moduleId): ClientType|null
	{
		return $this->repository->get($code, $moduleId);
	}

	public function getList(): ClientTypeCollection
	{
		return $this->repository->getList();
	}
}
