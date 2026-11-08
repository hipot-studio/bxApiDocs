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
			PromotionType::DEADLINE_BANNER => new DeadlineBannerPromotion(),
			PromotionType::RESPONSIBLE_BANNER => new ResponsibleBannerPromotion(),
			PromotionType::DEADLINE_AHA => new DeadlineAhaPromotion(),
			PromotionType::TASKS_CONSULTATION_BUTTON => new ConsultationButton(),
			PromotionType::TASKS_KANBAN_FIELD_SORT => new KanbanFieldSort(),
			PromotionType::TASKS_FILES_VIEW => new FilesViewPromotion(),
		};
	}
}
