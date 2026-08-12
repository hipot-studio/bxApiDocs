<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Bitrix24\Service;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Helper\Feature;

final class ProjectsTrialService
{
	public function turnOnTrialIfNeeded(): void
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return;
		}

		if (Feature::canTurnOnTrial(Feature::PROJECTS_GROUPS))
		{
			Feature::turnOnTrial(Feature::PROJECTS_GROUPS);
		}
	}
}
