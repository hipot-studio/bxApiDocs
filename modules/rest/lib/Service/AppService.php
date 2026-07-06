<?php

declare(strict_types=1);

namespace Bitrix\Rest\Service;

use Bitrix\Rest\Contract;
use Bitrix\Rest\Internal;
use Bitrix\Rest\Internal\Entity\Application\AppCollection;

class AppService implements Contract\Service\AppService
{
	public function __construct(
		private readonly Internal\Repository\Application\AppRepository $appRepository,
	)
	{}

	public function getPaidApplications(): AppCollection
	{
		return $this->appRepository->getPaidApplications();
	}

	public function hasPaidApps(): bool
	{
		return $this->appRepository->getPaidApplications(0, 1)->isEmpty() !== true;
	}

	public function getInstalledAppsCount(): int
	{
		return $this->appRepository->getInstalledAppsCount();
	}
}
