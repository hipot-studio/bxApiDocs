<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Promotion;

use Bitrix\Main\Config\Feature;
use Bitrix\Tasks\Feature\RegularTasksSectionFeature;

/**
 * Онбординг раздела «Регулярные задачи» (P5.T2).
 *
 * Показывается при включённом флаге раздела и пока не просмотрено. Контракт «просмотрено» —
 * существующий `tasks.promotion.setViewed` через ui.banner-dispatcher (новый endpoint
 * не вводится). Три касания онбординга (анонс, внутри раздела, чип карточки)
 * различаются на фронте; backend отдаёт лишь тип и «просмотрено» (AC-S-052/055).
 */
class RegularTasksPromotion extends AbstractPromotion
{
	public function getPromotionType(): PromotionType
	{
		return PromotionType::REGULAR_TASKS;
	}

	public function shouldShow(int $userId): bool
	{
		return Feature::isEnabled(RegularTasksSectionFeature::class) && !$this->isViewed($userId);
	}
}
