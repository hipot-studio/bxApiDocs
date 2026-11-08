<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Provider;

use Bitrix\Vibecodeconnector\Internal\Entity\Balance\VibeBalanceAction;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\BalanceAlertResolver;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\BalanceSyncCoordinator;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\PurchasePermissionService;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\PurchaseUrlProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\Storage\BalanceOptionStateStorage;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\TopUpRequestService;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventTargetProvider;
use Bitrix\Vibecodeconnector\Public\Dto\VibeBalanceProjection;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

final class VibeBalanceProvider
{
	/** @var array<int, VibeBalanceProjection> */
	private array $memo = [];

	public function __construct(
		private readonly BalanceOptionStateStorage $storage = new BalanceOptionStateStorage(),
		private readonly PurchaseUrlProvider $purchaseUrlProvider = new PurchaseUrlProvider(),
		private readonly BalanceAlertResolver $balanceAlertResolver = new BalanceAlertResolver(),
		private readonly ?bool $canPurchase = null,
	)
	{
	}

	public function getForCurrentUser(): VibeBalanceProjection
	{
		return $this->getForUser((int)\Bitrix\Main\Engine\CurrentUser::get()->getId());
	}

	public function getForUser(int $userId): VibeBalanceProjection
	{
		if (isset($this->memo[$userId]))
		{
			return $this->memo[$userId];
		}

		$availability = new AvailabilityService();
		if (!$availability->isVibeBalanceEnabled() || !$availability->isAvailableForUser($userId))
		{
			return $this->memo[$userId] = new VibeBalanceProjection(false, null, VibeBalanceAction::None);
		}

		$state = $this->storage->get();
		$needsBootstrap = ($state->version === null && $state->enqueuedVersion === null)
			|| $state->requestedVersion > ($state->enqueuedVersion ?? -1);
		if ($needsBootstrap)
		{
			$this->requestBalance($state->requestedVersion);
		}

		$action = $this->resolveAction($userId);
		$purchaseUrl = $action === VibeBalanceAction::Purchase && $state->balance !== null
			? $this->purchaseUrlProvider->getUrl()
			: null;

		return $this->memo[$userId] = new VibeBalanceProjection(
			true,
			$state->balance,
			$action,
			$purchaseUrl,
			(new VibecodeUrlProvider())->getDashboardUrl($userId),
			$this->balanceAlertResolver->resolve($state->balance),
			$state->lastFetchedAt,
		);
	}

	private function resolveAction(int $userId): VibeBalanceAction
	{
		$canPurchase = $this->canPurchase ?? (new PurchasePermissionService())->canPurchase($userId);
		if ($canPurchase)
		{
			return VibeBalanceAction::Purchase;
		}

		return (new TopUpRequestService())->hasActive($userId)
			? VibeBalanceAction::Requested
			: VibeBalanceAction::Request
		;
	}

	private function requestBalance(int $requestedVersion): void
	{
		$coordinator = new BalanceSyncCoordinator();
		foreach ((new UserEventTargetProvider())->listAll() as $target)
		{
			$coordinator->request($target, $requestedVersion);
		}
	}
}
