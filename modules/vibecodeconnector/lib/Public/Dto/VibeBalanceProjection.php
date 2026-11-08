<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Dto;

use Bitrix\Vibecodeconnector\Internal\Entity\Balance\VibeBalanceAction;
use Bitrix\Vibecodeconnector\Internal\Entity\Balance\VibeBalanceAlert;

final readonly class VibeBalanceProjection
{
	private ?string $dashboardUrl;
	private ?int $lastFetchedAt;

	public function __construct(
		private bool $isAvailable,
		private ?string $balance,
		private VibeBalanceAction $action,
		private ?string $purchaseUrl = null,
		?string $dashboardUrl = null,
		private VibeBalanceAlert $balanceAlert = VibeBalanceAlert::None,
		?int $lastFetchedAt = null,
	)
	{
		$this->dashboardUrl = $isAvailable && $dashboardUrl !== '' ? $dashboardUrl : null;
		$this->lastFetchedAt = $isAvailable && $balance !== null && $lastFetchedAt > 0 ? $lastFetchedAt : null;
	}

	public function toArray(): array
	{
		return [
			'isAvailable' => $this->isAvailable,
			'balanceState' => $this->balance === null ? 'unavailable' : 'available',
			'balance' => $this->balance,
			'balanceAlert' => $this->balanceAlert->value,
			'action' => $this->action->value,
			'purchaseUrl' => $this->purchaseUrl,
			'dashboardUrl' => $this->dashboardUrl,
			'lastFetchedAt' => $this->lastFetchedAt,
		];
	}
}
