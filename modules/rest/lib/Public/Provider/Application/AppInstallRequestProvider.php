<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Application;

use Bitrix\Rest\Internal\Entity\CurrentUser;
use Bitrix\Rest\Internal\Repository\Application\AppInstallRequest\AppInstallRequestRepository;

class AppInstallRequestProvider
{
	public function __construct(
		private readonly AppInstallRequestRepository $repository = new AppInstallRequestRepository(),
	)
	{
	}

	public function hasActivePendingRequest(string $appCode, ?string $appVersion, ?int $userId = null): bool
	{
		$userId ??= (int)(CurrentUser::getInstance()->getId() ?? 0);

		if ($appCode === '' || $userId <= 0)
		{
			return false;
		}

		$version = ($appVersion !== null && $appVersion !== '') ? $appVersion : '1';

		return $this->repository->hasActivePendingByUser($appCode, $version, $userId);
	}
}
