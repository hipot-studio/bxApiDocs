<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\CloudSharedVerifier;

final class AvailabilityService
{
	public function __construct(
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
		private readonly CloudSharedVerifier $cloudSharedVerifier = new CloudSharedVerifier(),
	) {
	}

	public function isReady(): bool
	{
		return $this->pairingRepository->hasAny() || $this->cloudSharedVerifier->isConfigured();
	}

	public function isEnabled(): bool
	{
		return Option::get('vibecodeconnector', 'is_ready', 'N') === 'Y';
	}

	public function setEnabled(bool $value): void
	{
		Option::set('vibecodeconnector', 'is_ready', $value ? 'Y' : 'N');
	}
}
