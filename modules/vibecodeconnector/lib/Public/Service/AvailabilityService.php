<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Service;

use Bitrix\Main\Config\Feature;
use Bitrix\Vibecodeconnector\Internal\Config\Feature\VibeBalance;
use Bitrix\Vibecodeconnector\Internal\Integration\Intranet\IntranetGate;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\CloudSharedVerifier;
use Bitrix\Vibecodeconnector\Internal\Service\Availability\PortalReadiness;

class AvailabilityService
{
	/** @var array<int, bool> */
	private static array $allowedMemo = [];

	private ?PortalReadiness $portalReadiness = null;

	public function __construct(
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
		private readonly CloudSharedVerifier $cloudSharedVerifier = new CloudSharedVerifier(),
		private readonly IntranetGate $intranetGate = new IntranetGate(),
	) {
	}

	public function isAvailableForUser(int $userId): bool
	{
		return $userId > 0 && $this->isEnabled() && $this->isAllowedForUser($userId);
	}

	public function isVibeBalanceEnabled(): bool
	{
		return Feature::isEnabled(VibeBalance::class);
	}

	/**
	 * Whether the catalog is allowed to the user type at all, regardless of the portal toggle.
	 */
	public function isAllowedForUser(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if (!array_key_exists($userId, self::$allowedMemo))
		{
			self::$allowedMemo[$userId] = $this->intranetGate->isIntranet($userId);
		}

		return self::$allowedMemo[$userId];
	}

	/**
	 * A cloud portal is ready as soon as the shared slot serves it: a missing key is obtained
	 * on the first incoming request, so readiness must not wait for the key to be stored.
	 */
	public function isReady(): bool
	{
		return $this->portalReadiness()->isReady();
	}

	public function isEnabled(): bool
	{
		return $this->portalReadiness()->isVisible();
	}

	public function setEnabled(bool $value): void
	{
		$this->portalReadiness()->setVisible($value);
		self::$allowedMemo = [];
	}

	private function portalReadiness(): PortalReadiness
	{
		return $this->portalReadiness ??= new PortalReadiness($this->cloudSharedVerifier, $this->pairingRepository);
	}
}
