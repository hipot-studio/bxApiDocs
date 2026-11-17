<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Promotion;

use Bitrix\Main\Loader;
use Bitrix\Tasks\Integration\CRM\CrmInTasksFeature;

/**
 * Onboarding hint for the task filter additions shipped with "CRM in tasks".
 *
 * The promotion answers whether the user is eligible at all. The place where the hint is mounted
 * adds the context terms (regular task list with a filter); they are not part of this class.
 */
final class TasksNewFilters extends AbstractPromotion
{
	public function getPromotionType(): PromotionType
	{
		return PromotionType::TASKS_NEW_FILTERS;
	}

	public function shouldShow(int $userId): bool
	{
		if (!CrmInTasksFeature::isOn())
		{
			return false;
		}

		if ($this->isViewed($userId))
		{
			return false;
		}

		if ($userId <= 0)
		{
			return false;
		}

		// The CRM_ITEM field the hint advertises needs the crm module on top of the toggle,
		// the same way Helper\Filter::getFilters() gates the field itself.
		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		return true;
	}
}
