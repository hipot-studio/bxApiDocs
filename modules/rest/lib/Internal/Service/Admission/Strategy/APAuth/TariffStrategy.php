<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission\Strategy\APAuth;

use Bitrix\Rest\Contract\Service\APAuth\PasswordService;
use Bitrix\Rest\Internal\Exception\Payment\FeatureNotAvailableOnCurrentPlanException;
use Bitrix\Rest\Internal\Integration\Bitrix24\TariffAccessService;
use Bitrix\Rest\Internal\Service\Admission\DenialStrategyInterface;
use Bitrix\Rest\Internal\Service\Admission\Verdict;

final class TariffStrategy implements DenialStrategyInterface
{
	public function __construct(
		private readonly int $passwordId,
		private readonly TariffAccessService $tariffAccessService,
		private readonly PasswordService $passwordService,
		private readonly ?\CRestServer $restServer,
	)
	{
	}

	public function resolve(): ?Verdict
	{
		$availability = $this->tariffAccessService->getRestAccessAvailability();
		if ($availability === null)
		{
			return null;
		}

		if ($availability)
		{
			return Verdict::grant();
		}

		$method = $this->restServer?->getMethod();
		$isAPAuthAvailable = $this->passwordService->isSystemPasswordById($this->passwordId)
			&& ($method === null || in_array($method, SystemPasswordAllowedMethods::METHODS, true));

		return $isAPAuthAvailable
			? Verdict::grant()
			: Verdict::deny(new FeatureNotAvailableOnCurrentPlanException());
	}
}
