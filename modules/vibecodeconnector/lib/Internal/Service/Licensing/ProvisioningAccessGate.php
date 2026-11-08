<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Licensing;

use Bitrix\Vibecodeconnector\Internal\Exception\ProvisioningFailedException;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibePlusPolicy;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibePlusUpsellProvider;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\ProvisioningAccessPolicy;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\EntryPoint;

final class ProvisioningAccessGate
{
	public const ERROR_CODE = 'FEATURE_NOT_AVAILABLE_ON_CURRENT_PLAN';
	public const ERROR_MESSAGE = 'Feature is not available on the current plan.';

	public function __construct(
		private readonly VibePlusPolicy $vibePlusPolicy = new VibePlusPolicy(),
		private readonly ?ProvisioningAccessPolicy $restProvisioningPolicy = null,
		private readonly VibePlusUpsellProvider $upsellProvider = new VibePlusUpsellProvider(),
	)
	{
	}

	public function ensureRestProvisioningAvailable(EntryPoint $entryPoint, int $userId = 0): void
	{
		$availability = $this->vibePlusPolicy->getAvailability();
		if ($availability === false)
		{
			throw new ProvisioningFailedException(
				self::ERROR_MESSAGE,
				self::ERROR_CODE,
				$this->upsellProvider->getForUser($userId)?->toArray(),
			);
		}

		$restProvisioningPolicy = $this->restProvisioningPolicy ?? new ProvisioningAccessPolicy();
		if ($entryPoint->isCowork())
		{
			$restProvisioningPolicy->ensureRestModuleAvailable();

			return;
		}

		$restProvisioningPolicy->ensureAvailable($availability !== true);
	}
}
