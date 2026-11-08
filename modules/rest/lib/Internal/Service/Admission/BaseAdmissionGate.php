<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission;

use Bitrix\Rest\Internal\Exception\Admission\RestAccessDenialExceptionInterface;

abstract class BaseAdmissionGate implements AdmissionGateInterface
{
	final public function ensureAdmitted(): void
	{
		$grantingStrategies = null;

		foreach ($this->getDenialStrategies() as $strategy)
		{
			$verdict = $strategy->resolve();
			if ($verdict === null)
			{
				continue;
			}

			$denial = $verdict->getDenial();
			if ($denial === null)
			{
				return;
			}

			$grantingStrategies ??= $this->collectGrantingStrategies();
			$this->resolveGranted($denial, $grantingStrategies);
		}
	}

	private function collectGrantingStrategies(): array
	{
		$strategies = [];
		foreach ($this->getGrantingStrategies() as $strategy)
		{
			$strategies[] = $strategy;
		}

		return $strategies;
	}

	private function resolveGranted(RestAccessDenialExceptionInterface $denial, array $grantingStrategies): void
	{
		foreach ($grantingStrategies as $strategy)
		{
			if ($strategy->grants($denial))
			{
				return;
			}
		}

		throw $denial;
	}

	abstract protected function getDenialStrategies(): iterable;

	abstract protected function getGrantingStrategies(): iterable;
}
