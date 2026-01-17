<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Collection\CompanyCollection;
use Bitrix\Main\Result;

class CompanyFeedSender
{
	public function __construct(
		private readonly ApiClient $apiClient,
		private readonly CompanyFeedHashService $feedHashService,
	)
	{
	}

	public function sendFeed(CompanyCollection $companyCollection): Result
	{
		$result = $this->apiClient->saveCompaniesFeed($companyCollection);
		if ($result->isSuccess())
		{
			$this->feedHashService->save($companyCollection);
		}

		return $result;
	}
}
