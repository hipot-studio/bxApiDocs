<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing;

use Bitrix\Mobile\Internal\Entity\Marketing\RuleDefinition;

/**
 * Rules of one scenario, written in code or built from data. The registry judges each of them on its
 * own when the set is plugged in.
 */
interface RuleSet
{
	/**
	 * @return RuleDefinition[]
	 */
	public function getRules(): array;
}
