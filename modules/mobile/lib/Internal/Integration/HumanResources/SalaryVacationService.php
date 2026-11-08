<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Loader;

/**
 * Thin proxy to the humanresources HCM Link salary/vacation API, narrowed to the
 * company list. Access logic (region and API gates) stays in humanresources.
 */
final class SalaryVacationService
{
	/**
	 * @return array{companies: list<array>}
	 */
	public function getCompanyList(int $userId): array
	{
		if (!$this->isHcmLinkAvailable())
		{
			return ['companies' => []];
		}

		return ['companies' => $this->fetchOwnedCompanies($userId)];
	}

	/**
	 * Both gates the salary/vacation actions check themselves: the section must not be
	 * shown when the flow behind it would answer with access denied.
	 */
	private function isHcmLinkAvailable(): bool
	{
		return Loader::includeModule('humanresources')
			&& method_exists(Feature::class, 'isHcmLinkSalaryVacationApiAvailable')
			&& method_exists(Container::class, 'getHcmLinkSalaryVacationApiService')
			&& Feature::instance()->isHcmLinkAvailable()
			&& Feature::instance()->isHcmLinkSalaryVacationApiAvailable();
	}

	/**
	 * Companies own-scoped by userId (only those with an employee mapped onto the user).
	 */
	private function fetchOwnedCompanies(int $userId): array
	{
		return Container::getHcmLinkSalaryVacationApiService()
			->getOwnedCompanies($userId)['companies'] ?? [];
	}
}
