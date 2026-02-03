<?php

namespace Bitrix\Tasks\Promotion;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Integration\Bitrix24\Portal;
use Bitrix\Tasks\V2\FormV2Feature;

class TasksNewCard extends AbstractPromotion
{
	public function getPromotionType(): PromotionType
	{
		return PromotionType::TASKS_NEW_CARD;
	}

	public function shouldShow(int $userId): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return false;
		}

		if (!FormV2Feature::isOn() || $this->isViewed($userId) || !CurrentUser::get()->getId())
		{
			return false;
		}

		$portalCreateDate = (new Portal())->getCreationDateTime();
		$userDateRegister = CurrentUser::get()->getDateRegister();
		$suitablePortalCreationDate = $this->getMinimumSuitablePortalCreationDate();

		return ($portalCreateDate?->getTimestamp() < $suitablePortalCreationDate->getTimestamp())
			&& (!$userDateRegister || $userDateRegister->getTimestamp() <= $suitablePortalCreationDate->getTimestamp())
		;
	}

	private function getMinimumSuitablePortalCreationDate(): DateTime
	{
		return Loader::includeModule('bitrix24')
			? new DateTime('2025-11-26', 'Y-m-d')
			: new DateTime('2026-01-21', 'Y-m-d')
		;
	}
}
