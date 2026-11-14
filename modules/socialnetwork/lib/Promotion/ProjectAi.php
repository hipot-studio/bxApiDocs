<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Promotion;

use Bitrix\AI\Facade\Portal as AiPortal;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Internals\Registry\UserRegistry;
use Bitrix\Socialnetwork\V2\Feature;

class ProjectAi extends AbstractPromotion
{
	public function getPromotionType(): PromotionType
	{
		return PromotionType::PROJECT_AI;
	}

	public function shouldShow(int $userId): bool
	{
		if (!Feature::isNewProjectsOn())
		{
			return false;
		}

		if (!Feature::isOldPortalForNewProject())
		{
			return false;
		}

		if ($this->isViewed($userId))
		{
			return false;
		}

		if (!$this->doesUserHaveActiveProjects($userId))
		{
			return false;
		}

		// The facade method ships with the newer ai release; on an older one the gate
		// just cannot apply and the promo keeps its previous behaviour.
		if (
			Loader::includeModule('ai')
			&& method_exists(AiPortal::class, 'hasEnabledAiScenario')
			&& !AiPortal::hasEnabledAiScenario()
		)
		{
			return false;
		}

		return true;
	}

	private function doesUserHaveActiveProjects(int $userId): bool
	{
		return !empty(UserRegistry::getInstance($userId)->getUserGroups());
	}
}
