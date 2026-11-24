<?php

namespace Bitrix\BIConnector\Public\Features;

use Bitrix\BIConnector\Configuration;
use Bitrix\Main\Config\Feature\AbstractFlag;

final class McpBiConnection extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return Configuration\Feature::checkFeatureOption(Configuration\Feature::MCP_BI_CONNECTION);
	}
}
