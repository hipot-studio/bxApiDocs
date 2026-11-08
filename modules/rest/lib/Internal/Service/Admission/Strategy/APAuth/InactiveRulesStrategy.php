<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission\Strategy\APAuth;

use Bitrix\Main\ModuleManager;
use Bitrix\Rest\Internal\Service\Admission\DenialStrategyInterface;
use Bitrix\Rest\Internal\Service\Admission\Verdict;

final class InactiveRulesStrategy implements DenialStrategyInterface
{
	public function resolve(): ?Verdict
	{
		return ModuleManager::isModuleInstalled('bitrix24') ? null : Verdict::grant();
	}
}
