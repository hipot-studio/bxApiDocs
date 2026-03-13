<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Service\User;

use Bitrix\Intranet\Internal\Repository\IntranetUserRepository;
use Bitrix\Main\DI\ServiceLocator;

class UserInitializationStatusService
{
	private IntranetUserRepository $repository;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get(IntranetUserRepository::class);
	}

	public function isInitialized(int $userId): bool
	{
		return (bool)$this->repository->getByUserId($userId)?->isInitialized();
	}
}
