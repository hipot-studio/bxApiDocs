<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Infrastructure\Rest\Support\ApplicationErrors;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCatalogContractor;

final class ContractorService
{
	use ApplicationErrors;

	public function __construct(
		private readonly AccessSupport $accessSupport,
	)
	{
	}

	public function createContractor(array $fields): Result
	{
		$access = $this->accessSupport->ensureStoreViewAction();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$companyName = $fields['companyName'] ?? null;
		if ($companyName === null || $companyName === '')
		{
			return ServiceResult::validation([new Error('Empty name')]);
		}

		$userId = (int)CurrentUser::get()->getId();
		$addFields = [
			'PERSON_TYPE' => CONTRACTOR_JURIDICAL,
			'COMPANY' => $companyName,
			'CREATED_BY' => $userId,
			'MODIFIED_BY' => $userId,
		];

		$contractorId = (int)CCatalogContractor::add($addFields);
		if ($contractorId <= 0)
		{
			return ServiceResult::validation([
				$this->getLastApplicationError() ?? new Error('Error adding contractor'),
			]);
		}

		return ServiceResult::success([
			'item' => [
				'id' => $contractorId,
				'companyName' => (string)$companyName,
			],
		]);
	}
}
