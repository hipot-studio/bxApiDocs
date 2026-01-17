<?php

namespace Bitrix\Tasks\Promotion;

use Bitrix\Tasks\Internals\Counter;
use Bitrix\Tasks\V2\FormV2Feature;

class TasksNewChatButton extends AbstractPromotion
{
	public function getPromotionType(): PromotionType
	{
		return PromotionType::TASKS_NEW_CHAT_BUTTON;
	}

	public function shouldShow(int $userId): bool
	{
		if (!FormV2Feature::isOn() || $this->isViewed($userId))
		{
			return false;
		}

		return $this->hasChatCounter($userId);
	}

	private function hasChatCounter(int $userId): bool
	{
		$counterValue = Counter::getInstance($userId)->get(Counter\CounterDictionary::COUNTER_NEW_COMMENTS);

		return $counterValue > 0;
	}
}
