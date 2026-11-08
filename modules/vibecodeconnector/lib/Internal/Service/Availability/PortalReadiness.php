<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Availability;

use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;
use Bitrix\Vibecodeconnector\Internal\Integration\Immobile\VibeButtonAvailability;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\CloudSharedVerifier;

final class PortalReadiness
{
	private const OPTION_IS_READY = 'is_ready';
	private const OPTION_IS_READY_SET_AT = 'is_ready_set_at';

	public function __construct(
		private readonly CloudSharedVerifier $cloudSharedVerifier = new CloudSharedVerifier(),
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
		private readonly ModuleOptions $options = new ModuleOptions(),
		private readonly VibeButtonAvailability $vibeButtonAvailability = new VibeButtonAvailability(),
	) {
	}

	public function isReady(): bool
	{
		return $this->cloudSharedVerifier->isSlotAvailable() || $this->pairingRepository->hasAny();
	}

	public function isVisible(): bool
	{
		return $this->options->get(self::OPTION_IS_READY, 'N') === 'Y';
	}

	public function setVisible(bool $value): void
	{
		$this->options->set(self::OPTION_IS_READY, $value ? 'Y' : 'N');
		$this->options->set(self::OPTION_IS_READY_SET_AT, (string)(new DateTime())->getTimestamp());
		$this->vibeButtonAvailability->set($value);
	}
}
