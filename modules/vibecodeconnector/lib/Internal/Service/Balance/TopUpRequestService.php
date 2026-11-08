<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Balance;

use Bitrix\Vibecodeconnector\Internal\Entity\Balance\BalanceValue;
use Bitrix\Vibecodeconnector\Internal\Entity\Balance\TopUpRequestStatus;
use Bitrix\Vibecodeconnector\Internal\Repository\Balance\TopUpRequestRepository;

final class TopUpRequestService
{
	public function __construct(
		private readonly TopUpRequestRepository $repository = new TopUpRequestRepository(),
		private readonly TopUpNotificationService $notificationService = new TopUpNotificationService(),
		private readonly ?AdminRecipientProvider $recipientProvider = null,
	)
	{
	}

	public function request(int $userId, string $balanceSnapshot): string
	{
		if (!(new BalanceValidator())->isValid($balanceSnapshot))
		{
			throw new \InvalidArgumentException('Balance must be a decimal string');
		}

		$row = $this->repository->getByUserId($userId);
		if ($row === null)
		{
			$this->repository->saveClosed($userId);
			$row = $this->repository->getByUserId($userId);
		}

		if ($row === null)
		{
			throw new \RuntimeException('Unable to create top-up request');
		}

		if ($row['STATUS'] === TopUpRequestStatus::Active->value)
		{
			return 'already_requested';
		}

		$provider = $this->recipientProvider ?? new AdminRecipientProvider();
		if (!$this->notificationService->notifyAll($userId, $provider->getAdminUserIds(), $balanceSnapshot))
		{
			throw new \RuntimeException('Unable to notify all administrators');
		}

		$this->repository->activate((int)$row['ID']);

		return 'created';
	}

	public function closeActiveOnBalanceGrowth(?string $previousBalance, string $newBalance): void
	{
		if ($previousBalance === null)
		{
			return;
		}

		if ((new BalanceValue($newBalance))->isGreaterThan(new BalanceValue($previousBalance)))
		{
			$this->repository->closeAllActive();
		}
	}

	public function hasActive(int $userId): bool
	{
		return $this->repository->hasActive($userId);
	}
}
