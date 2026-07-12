<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Controller\Filter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Socialnetwork\Collab\CollabFeature;
use Bitrix\Socialnetwork\V2\Feature;

class FeatureFilter extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		if (Feature::isNewProjectsOn())
		{
			$this->addError(new Error('Collab feature not available', 'new_projects_on'));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		if (!CollabFeature::isFeatureEnabled())
		{
			$this->addError(new Error('Collab feature not available', 'collab_feature_required'));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}