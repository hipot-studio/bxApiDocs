<?php

namespace Bitrix\Tasks\Promotion;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Integration\Bitrix24\Portal;

class KanbanFieldSort extends AbstractPromotion
{
	public function getPromotionType(): PromotionType
	{
		return PromotionType::TASKS_KANBAN_FIELD_SORT;
	}

	public function shouldShow(int $userId): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return false;
		}

		if ($this->isViewed($userId) || !$this->isSuitableRegion())
		{
			return false;
		}

		// CurrentUser::get() builds the user, so it goes after the cheap checks
		$currentUser = CurrentUser::get();

		if (!$currentUser->getId())
		{
			return false;
		}

		return $this->isSuitablePortal(
			(new Portal())->getCreationDateTime(),
			$currentUser->getDateRegister(),
		);
	}

	protected function isSuitablePortal(?DateTime $portalCreateDate, ?DateTime $userDateRegister): bool
	{
		$suitablePortalCreationDate = $this->getMinimumSuitablePortalCreationDate();

		return ($portalCreateDate?->getTimestamp() < $suitablePortalCreationDate->getTimestamp())
			&& (!$userDateRegister || $userDateRegister->getTimestamp() <= $suitablePortalCreationDate->getTimestamp())
		;
	}

	private function getMinimumSuitablePortalCreationDate(): DateTime
	{
		return Loader::includeModule('bitrix24')
			? new DateTime('2026-09-09', 'Y-m-d')
			: new DateTime('2026-09-11', 'Y-m-d')
		;
	}

	private function isSuitableRegion(): bool
	{
		$region = Application::getInstance()->getLicense()->getRegion();

		return $region !== 'cn';
	}
}
