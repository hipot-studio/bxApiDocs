<?php

namespace Bitrix\Sign\Item\Document\Configure;

use Bitrix\Sign\Contract;

/**
 * What the plan has already changed in the document. Filled step by step while the plan is
 * applied, so the compensation knows what to roll back and the process log knows what stayed.
 */
final class Journal implements Contract\Item
{
	/** @var Step[] */
	private array $applied = [];

	/** @var Step[] */
	private array $irreversiblyApplied = [];

	/** @var Step[] */
	private array $unchanged = [];

	public function noteApplied(Step $step): void
	{
		$this->applied[] = $step;

		if (!$step->reversible)
		{
			$this->irreversiblyApplied[] = $step;
		}
	}

	/**
	 * The external service has accepted the change while the local write failed: the step has
	 * not been applied, and it cannot be rolled back either.
	 */
	public function noteExternallyApplied(Step $step): void
	{
		$this->irreversiblyApplied[] = $step;
	}

	/**
	 * The step found the document already carrying what it was going to write. Such a step is
	 * kept out of the applied ones: it has changed nothing, so the compensation would take back
	 * a state nobody has moved.
	 */
	public function noteUnchanged(Step $step): void
	{
		$this->unchanged[] = $step;
	}

	/**
	 * @return Step[]
	 */
	public function listApplied(): array
	{
		return $this->applied;
	}

	/**
	 * @return Step[]
	 */
	public function listIrreversiblyApplied(): array
	{
		return $this->irreversiblyApplied;
	}

	/**
	 * @return Step[]
	 */
	public function listUnchanged(): array
	{
		return $this->unchanged;
	}
}
