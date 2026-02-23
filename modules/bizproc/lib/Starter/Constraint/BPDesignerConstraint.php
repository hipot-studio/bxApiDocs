<?php

namespace Bitrix\Bizproc\Starter\Constraint;

use Bitrix\Bizproc\Internal\Service\Feature\BpDesignerFeature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

final class BPDesignerConstraint implements ConstraintInterface
{
	private ?Error $lastError = null;

	public function isSatisfied(): bool
	{
		$bpDesignerFeature = ServiceLocator::getInstance()->get(BpDesignerFeature::class);

		if (!$bpDesignerFeature->isAvailable())
		{
			$this->lastError = $bpDesignerFeature->makeUnavailableByTariffError();
			return false;
		}

		return true;
	}

	public function getError(): ?Error
	{
		return $this->lastError;
	}
}
