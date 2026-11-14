<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Gate;

interface BulkStepGate extends StepGate
{
	/** @param array<int, GateContext> $contexts */
	public function preload(array $contexts): void;
}
