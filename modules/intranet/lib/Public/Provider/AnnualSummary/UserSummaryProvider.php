<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\AnnualSummary;

use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Intranet\Internal\Service\AnnualSummary\AdminSummaryPresentation;
use Bitrix\Intranet\Public\Service\AnnualSummary\AdminAccess;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectNotFoundException;

final class UserSummaryProvider
{
	public function getByUserId(int $userId): array
	{
		(new AdminAccess())->assertAllowed();

		if ($userId <= 0)
		{
			throw new ArgumentException('User ID must be positive.', 'userId');
		}

		$repository = new AnnualSummaryRepository();
		$user = (new UserRepository())->getUserById($userId);
		if ($user === null)
		{
			throw new ObjectNotFoundException('User is unavailable.');
		}

		$region = strtoupper((string)Application::getInstance()->getLicense()->getRegion());

		return (new AdminSummaryPresentation())->build($user->toArray(), $repository->findByUserId($userId), $region);
	}
}
