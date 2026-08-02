<?php

namespace Bitrix\Bizproc\Starter\Constraint;

use Bitrix\Bizproc\Internal\Service\Feature\BpDesignerFeature;
use Bitrix\Bizproc\Internal\Service\Tariff\TariffChecker;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

final class ScenarioBPDesignerConstraint implements ConstraintInterface
{
	private ?Error $lastError = null;

	public function isSatisfied(): bool
	{
		if (TariffChecker::isBasicOrHigher())
		{
			return true;
		}

		$bpDesignerFeature = ServiceLocator::getInstance()->get(BpDesignerFeature::class);
		if ($bpDesignerFeature->isAvailable())
		{
			return true;
		}

		$this->lastError = $bpDesignerFeature->makeUnavailableByTariffError();

		return false;
	}

	public function getError(): ?Error
	{
		return $this->lastError;
	}
}
