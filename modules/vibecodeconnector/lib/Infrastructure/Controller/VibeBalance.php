<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\PurchasePermissionService;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\Storage\BalanceOptionStateStorage;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\TopUpRequestService;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

final class VibeBalance extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new HttpMethod([HttpMethod::METHOD_POST]),
		];
	}

	public function requestTopUpAction(CurrentUser $currentUser): ?array
	{
		$userId = (int)$currentUser->getId();
		if ($userId <= 0)
		{
			$this->addError(new \Bitrix\Main\Error('Access denied', 'ACCESS_DENIED'));

			return null;
		}

		$availability = new AvailabilityService();
		if (!$availability->isVibeBalanceEnabled() || !$availability->isAvailableForUser($userId))
		{
			$this->addError(new \Bitrix\Main\Error('VibeCode is unavailable', 'VIBECODE_UNAVAILABLE'));

			return null;
		}

		if ((new PurchasePermissionService())->canPurchase($userId))
		{
			$this->addError(new \Bitrix\Main\Error('Top-up request is not allowed', 'TOP_UP_REQUEST_NOT_ALLOWED'));

			return null;
		}

		try
		{
			$balance = (new BalanceOptionStateStorage())->get()->balance;
			if ($balance === null)
			{
				$this->addError(new \Bitrix\Main\Error('Balance is unavailable', 'BALANCE_UNAVAILABLE'));

				return null;
			}

			$requestState = (new TopUpRequestService())->request($userId, $balance);

			return ['requestState' => $requestState];
		}
		catch (\Throwable)
		{
			$this->addError(new \Bitrix\Main\Error('Unable to create top-up request', 'TOP_UP_REQUEST_FAILED'));

			return null;
		}
	}
}
