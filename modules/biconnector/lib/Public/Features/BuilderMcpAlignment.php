<?php

namespace Bitrix\BIConnector\Public\Features;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Feature\Context;

final class BuilderMcpAlignment extends AbstractFlag
{
	public function getRequirements(): array
	{
		return [
			fn (?Context $context = null) => Feature::isEnabled(BuilderMcpOperations::class, $context),
		];
	}
}
