<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Feature\Context;

final class DiagnosticLogFeature extends AbstractFlag
{
	public function getRequirements(): array
	{
		return [
			fn (Context $context): bool => (int)$context->userId > 0,
		];
	}
}
