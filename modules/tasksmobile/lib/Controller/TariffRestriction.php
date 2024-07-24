<?php

namespace Bitrix\TasksMobile\Controller;

use Bitrix\TasksMobile\Provider\FlowProvider;

class TariffRestriction extends Base
{
	protected function getQueryActionNames(): array
	{
		return [
			'getTariffRestrictions',
		];
	}

	/**
	 * @restMethod tasksmobile.TariffRestriction.getTariffRestrictions
	 * @return array
	 */
	public function getTariffRestrictionsAction(): array
	{
		return [
			...(new FlowProvider($this->getCurrentUser()->getId()))->getFeatureRestrictions(),
		];
	}
}
