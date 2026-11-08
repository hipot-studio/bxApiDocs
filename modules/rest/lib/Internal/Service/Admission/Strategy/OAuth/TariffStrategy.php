<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission\Strategy\OAuth;

use Bitrix\Rest\AppTable;
use Bitrix\Rest\Internal\Exception\Payment\FeatureNotAvailableOnCurrentPlanException;
use Bitrix\Rest\Internal\Integration\Bitrix24\TariffAccessService;
use Bitrix\Rest\Internal\Service\Admission\DenialStrategyInterface;
use Bitrix\Rest\Internal\Service\Admission\Verdict;

final class TariffStrategy implements DenialStrategyInterface
{
	public function __construct(
		private readonly string $clientId,
		private readonly TariffAccessService $tariffAccessService,
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

		if ($availability === false)
		{
			$appInfo = AppTable::getByClientId($this->clientId);
			$availability = is_array($appInfo)
				&& ($appInfo['STATUS'] ?? null) !== AppTable::STATUS_LOCAL;
		}

		return $availability === true
			? Verdict::grant()
			: Verdict::deny(new FeatureNotAvailableOnCurrentPlanException());
	}
}
