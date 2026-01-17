<?php

namespace Bitrix\Tasks\Promotion;

class PromotionFactory
{
	public function getByPromotionType(PromotionType $type): AbstractPromotion
	{
		return match ($type)
		{
			PromotionType::TASKS_AI => new TasksAi(),
			PromotionType::TASKS_NEW_CARD => new TasksNewCard(),
			PromotionType::TASKS_NEW_CHAT_BUTTON => new TasksNewChatButton(),
		};
	}
}
