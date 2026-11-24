<?php

namespace Bitrix\BIConnector\Public\Features;

use Bitrix\BIConnector\Configuration;
use Bitrix\Main\Config\Feature\AbstractFlag;

final class AiAgentsSection extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return Configuration\Feature::checkFeatureOption(Configuration\Feature::BI_AI_AGENTS_SECTION);
	}
}
