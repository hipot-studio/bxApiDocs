<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Superset\ActionFilter;
use Bitrix\BIConnector\Superset\UI\DashboardOnboarding\DashboardOnboardingFlag;
use Bitrix\BIConnector\Superset\UI\DashboardOnboarding\DashboardOnboardingService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;

class DashboardOnboarding extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new HttpMethod([HttpMethod::METHOD_POST]),
			new ActionFilter\BIConstructorAccess(),
		];
	}

	public function completeTourAction(): void
	{
		$this->markFlag(DashboardOnboardingFlag::TourPassed);
	}

	public function dismissEmptyDashboardCtaAction(): void
	{
		$this->markFlag(DashboardOnboardingFlag::EmptyDashboardCtaShown);
	}

	private function markFlag(DashboardOnboardingFlag $flag): void
	{
		$this->getDashboardOnboardingService()->markFlag($flag, (int)CurrentUser::get()->getId());
	}

	private function getDashboardOnboardingService(): DashboardOnboardingService
	{
		return ServiceLocator::getInstance()->get(DashboardOnboardingService::class);
	}
}
