<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Bizproc;

use Bitrix\Main\Loader;

class RuntimeGateway
{
	public function isFeatureEnabled(): bool
	{
		return Loader::includeModule('bizproc') && \CBPRuntime::isFeatureEnabled();
	}
}
