<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Interface;

interface FilterResultPropertyResolver
{
	public function supports(\CBPActivity $activity): bool;

	public function resolveProperties(\CBPActivity $activity): array;
}
