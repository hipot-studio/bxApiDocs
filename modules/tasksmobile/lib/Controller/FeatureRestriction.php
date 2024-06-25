<?php

namespace Bitrix\TasksMobile\Controller;

use Bitrix\TasksMobile\Provider\FlowProvider;
use Bitrix\TasksMobile\Settings;

class FeatureRestriction extends Base
{
	protected function getQueryActionNames(): array
	{
		return [
			'getFeatureRestrictions',
		];
	}

	/**
	 * @restMethod tasksmobile.FeatureRestriction.getFeatureRestrictions
	 * @return array
	 */
	public function getFeatureRestrictionsAction(): array
	{
		if (!Settings::getInstance()->isTaskFlowAvailable())
		{
			return [];
		}

		return (new FlowProvider($this->getCurrentUser()->getId()))->getFeatureRestrictions();
	}
}