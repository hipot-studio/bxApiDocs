<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Promo;

use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\UsersFloatingBannerFeature;
use Bitrix\Mobile\Internal\Integration\Bitrix24\ActiveEmployeeCount;
use Bitrix\Mobile\Menu\Promo\Dto\FloatingPromoData;
use Bitrix\Mobile\Provider\CommonUserDto;

final class FloatingPromoProvider
{
	private readonly ActiveEmployeeCount $activeEmployeeCount;
	private readonly EmployeeThreshold $employeeThreshold;

	public function __construct(
		private readonly CommonUserDto $user,
		?ActiveEmployeeCount $activeEmployeeCount = null,
	)
	{
		$this->activeEmployeeCount = $activeEmployeeCount ?? new ActiveEmployeeCount();
		$this->employeeThreshold = new EmployeeThreshold();
	}

	/**
	 * Returns the floating promo payload of the menu response.
	 * Recalculated on every request: it never goes into MenuListCache.
	 *
	 * @return array{usersBannerEnabled: bool}
	 */
	public function getData(): array
	{
		return FloatingPromoData::make([
			'usersBannerEnabled' => $this->isVisible(),
		])->toArray();
	}

	/**
	 * The flag is a rollout switch, not an access check: the invite right is applied on the client.
	 *
	 * The employee count is the only thing that tells the portal has outgrown the promo, and the
	 * menu answer is the only place that reads it, so the mark is raised from here. It is raised
	 * once per portal - every later answer leaves at the mark itself - and the dev page of the
	 * section takes it back.
	 */
	private function isVisible(): bool
	{
		if (Feature::isDisabled(UsersFloatingBannerFeature::class))
		{
			return false;
		}

		if ($this->user->isExtranet || $this->user->isCollaber || $this->user->isGuest)
		{
			return false;
		}

		if ($this->employeeThreshold->isReached())
		{
			return false;
		}

		$activeEmployeeCount = $this->activeEmployeeCount->get();
		if ($activeEmployeeCount >= EmployeeThreshold::COUNT)
		{
			$this->employeeThreshold->remember();

			return false;
		}

		return $activeEmployeeCount > 0;
	}
}
