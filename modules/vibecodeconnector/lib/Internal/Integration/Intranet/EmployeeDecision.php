<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Intranet;

final class EmployeeDecision
{
	public function __construct(
		public readonly array $employees,
		public readonly array $undecided,
	) {}
}
