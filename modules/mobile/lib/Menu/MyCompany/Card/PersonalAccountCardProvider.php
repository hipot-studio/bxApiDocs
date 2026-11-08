<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany\Card;

use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\PersonalAccountFeature;
use Bitrix\Mobile\Internal\Integration\HumanResources\SalaryVacationService;
use Bitrix\Mobile\Provider\CommonUserDto;

/**
 * Data of the personal account card: the companies whose salary and vacation the user may open.
 * A disabled feature flag short-circuits before any humanresources call.
 *
 * One humanresources call per answer: the visibility flag is derived from the list, and an empty
 * list also covers the disabled region gate.
 */
final class PersonalAccountCardProvider
{
	public function __construct(private readonly CommonUserDto $user)
	{
	}

	/**
	 * @return array{canUsePersonalAccount: bool, companies: list<array>}
	 */
	public function getData(): array
	{
		if (
			!Feature::isEnabled(PersonalAccountFeature::class)
			|| $this->user->isExtranet
			|| $this->user->isCollaber
			|| $this->user->isGuest
		)
		{
			return [
				'canUsePersonalAccount' => false,
				'companies' => [],
			];
		}

		$companies = (new SalaryVacationService())->getCompanyList($this->user->id)['companies'] ?? [];

		return [
			'canUsePersonalAccount' => !empty($companies),
			'companies' => $companies,
		];
	}
}
