<?php

namespace Bitrix\Tasks\Promotion;

use Bitrix\Main\Application;

class ConsultationButton extends AbstractPromotion
{
	public function getPromotionType(): PromotionType
	{
		return PromotionType::TASKS_CONSULTATION_BUTTON;
	}

	public function shouldShow(int $userId): bool
	{
		return !$this->isViewed($userId) && $this->isSuitableRegion();
	}

	private function isSuitableRegion(): bool
	{
		$region = Application::getInstance()->getLicense()->getRegion();

		return $region !== 'cn';
	}
}
