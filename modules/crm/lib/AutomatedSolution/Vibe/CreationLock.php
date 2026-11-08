<?php

namespace Bitrix\Crm\AutomatedSolution\Vibe;

use Bitrix\Main\Application;

final class CreationLock implements VibeLock
{
	private ?string $lockName = null;

	public function acquire(int $automatedSolutionId): bool
	{
		$this->lockName = "crm_automated_solution_vibe_{$automatedSolutionId}";

		return Application::getConnection()->lock($this->lockName);
	}

	public function release(): void
	{
		if ($this->lockName === null)
		{
			return;
		}

		Application::getConnection()->unlock($this->lockName);
		$this->lockName = null;
	}
}
