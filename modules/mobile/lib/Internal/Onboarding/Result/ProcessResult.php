<?php

namespace Bitrix\Mobile\Internal\Onboarding\Result;

use Bitrix\Main\Result;

class ProcessResult extends Result
{
	public function setProcessed(int $processed): self
	{
		$this->data['processed'] = $processed;

		return $this;
	}

	public function getProcessed(): int
	{
		return $this->data['processed'] ?? 0;
	}

	public function setSent(int $sent): self
	{
		$this->data['sent'] = $sent;

		return $this;
	}

	public function getSent(): int
	{
		return $this->data['sent'] ?? 0;
	}

	/**
	 * Users the pass sent nothing to. Derived rather than counted: a third number kept by
	 * hand is a number that can disagree with the two it is made of.
	 */
	public function getSkipped(): int
	{
		return $this->getProcessed() - $this->getSent();
	}

	/**
	 * Counts one user the pass sent nothing to, under the code of the outcome
	 * (OnboardingService::CODE_*): `skipped` says how many, these say why.
	 */
	public function addOutcome(string $code): self
	{
		$outcomes = $this->getOutcomes();
		$outcomes[$code] = ($outcomes[$code] ?? 0) + 1;
		$this->data['outcomes'] = $outcomes;

		return $this;
	}

	/**
	 * @return array<string, int>
	 */
	public function getOutcomes(): array
	{
		return $this->data['outcomes'] ?? [];
	}
}
