<?php

namespace Bitrix\BIConnector\Internal\Feature;

use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;

class SupersetPlacementResolver
{
	public function resolve(): string
	{
		return match (SupersetHostMode::getMode()) {
			SupersetHostMode::MODE_CLOUD => SqlLabRestGate::PLACEMENT_CLOUD,
			SupersetHostMode::MODE_SELFHOSTED => SqlLabRestGate::PLACEMENT_SELF_HOSTED,
			default => '',
		};
	}
}
