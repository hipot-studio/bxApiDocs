<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission\Strategy\Portal;

use Bitrix\Rest\Internal\Exception\Payment\FeatureNotAvailableOnCurrentPlanException;
use Bitrix\Rest\Internal\Integration\Bitrix24\TariffAccessService;
use Bitrix\Rest\Internal\Service\Admission\DenialStrategyInterface;
use Bitrix\Rest\Internal\Service\Admission\Verdict;

final class TariffStrategy implements DenialStrategyInterface
{
	public function __construct(private readonly TariffAccessService $tariffAccessService)
	{
	}

	public function resolve(): ?Verdict
	{
		$availability = $this->tariffAccessService->getRestAccessAvailability();
		if ($availability === null)
		{
			return null;
		}

		return $availability
			? Verdict::grant()
			: Verdict::deny(new FeatureNotAvailableOnCurrentPlanException());
	}
}
