<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission\Strategy;

use Bitrix\Rest\Engine\Access;
use Bitrix\Rest\Internal\Service\Admission\DenialStrategyInterface;
use Bitrix\Rest\Internal\Service\Admission\Verdict;

final class InactiveRulesStrategy implements DenialStrategyInterface
{
	public function resolve(): ?Verdict
	{
		return Access::isActiveRules() ? null : Verdict::grant();
	}
}
