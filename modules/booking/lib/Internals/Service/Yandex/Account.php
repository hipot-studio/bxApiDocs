<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Internals\Service\ModuleOptions;
use Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Collection\CompanyCollection;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class Account
{
	private const OPTION_ACCOUNT_ID = 'yandex_account_id';

	public function __construct(
		private readonly ApiClient $apiClient,
		private readonly CompanyFeedHashService $feedHashService,
	)
	{
	}

	public function register(CompanyCollection $companyCollection): Result
	{
		$result = $this->apiClient->register($companyCollection);

		if ($result->isSuccess())
		{
			$accountId = isset($result->getData()['accountId']) ? (int)$result->getData()['accountId'] : 0;
			if (!$accountId)
			{
				$result->addError(new Error('Account id is not specified'));

				return $result;
			}

			$this->feedHashService->save($companyCollection);
			CompanyFeedSenderAgent::install();
			$this->saveAccountId($accountId);
		}

		return $result;
	}

	public function unregister(): Result
	{
		$result = $this->apiClient->unregister();

		if ($result->isSuccess())
		{
			$this->feedHashService->reset();
			CompanyFeedSenderAgent::uninstall();
			$this->removeAccountId();
			ModuleOptions::deleteRequestedFromYandex();
		}

		return $result;
	}

	public function isRegistered(): bool
	{
		return $this->getAccountId() !== null;
	}

	private function getAccountId(): int|null
	{
		$accountId = Option::get('booking', self::OPTION_ACCOUNT_ID, null);

		return $accountId === null ? null : (int)$accountId;
	}

	private function saveAccountId(int $accountId): void
	{
		Option::set('booking', self::OPTION_ACCOUNT_ID, $accountId);
	}

	private function removeAccountId(): void
	{
		Option::delete('booking', ['name' => self::OPTION_ACCOUNT_ID]);
	}
}
