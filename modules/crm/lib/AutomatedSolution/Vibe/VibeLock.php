<?php

namespace Bitrix\Crm\AutomatedSolution\Vibe;

interface VibeLock
{
	public function acquire(int $automatedSolutionId): bool;

	public function release(): void;
}
