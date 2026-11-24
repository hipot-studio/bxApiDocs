<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller;

use Bitrix\Intranet\Public\Provider\AnnualSummary\UserSummaryProvider;
use Bitrix\Intranet\Public\Service\AnnualSummary\AdminAccess;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;

final class AnnualSummaryAdmin extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new HttpMethod([HttpMethod::METHOD_POST]),
		];
	}

	public function getUserAction(int $userId): array
	{
		(new AdminAccess())->assertAllowed();

		return (new UserSummaryProvider())->getByUserId($userId);
	}
}
