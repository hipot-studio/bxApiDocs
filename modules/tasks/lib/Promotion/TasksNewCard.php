<?php

namespace Bitrix\Tasks\Promotion;

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
		if (!FormV2Feature::isOn() || $this->isViewed($userId))
		{
			return false;
		}

		$portalCreateDate = (new Portal())->getCreationDateTime();
		$suitablePortalCreationDate = $this->getMinimumSuitablePortalCreationDate();

		return $portalCreateDate?->getTimestamp() < $suitablePortalCreationDate->getTimestamp();
	}

	private function getMinimumSuitablePortalCreationDate(): DateTime
	{
		return new DateTime('2025-11-26', 'Y-m-d');
	}
}
