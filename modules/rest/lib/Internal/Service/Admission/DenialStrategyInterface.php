<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission;

interface DenialStrategyInterface
{
	public function resolve(): ?Verdict;
}
