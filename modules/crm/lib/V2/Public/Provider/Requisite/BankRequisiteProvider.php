<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Requisite;

use Bitrix\Crm\V2\Internal\Service\Requisite\BankRequisiteService;
use Bitrix\Crm\BankDetailTable;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Provider\Requisite\RequisiteProvider;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteFilter;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteListContext;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteSort;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Result;
use Bitrix\Main\Error;

final class BankRequisiteProvider
{
	public const DATA_BANK_REQUISITE = 'bankRequisite';
	public const DATA_BANK_REQUISITES = 'bankRequisites';

	public function __construct(
		private readonly int $userId,
	)
	{
	}

	public function getById(EntityType $expectedOwnerType, int $id): Result
	{
		return (new BankRequisiteService($this->userId))->getById($expectedOwnerType, $id);
	}

	public function getList(
		BankRequisiteListContext $context,
		?BankRequisiteFilter $filter = null,
		?BankRequisiteSort $sort = null,
		?PagerInterface $pager = null,
	): Result
	{
		return (new BankRequisiteService($this->userId))->getList(
			$context,
			$filter,
			$sort,
			$pager,
		);
	}

	/** @return Result<ItemId> */
	public function resolveBankRequisiteOwner(int $bankRequisiteId, int $userId): Result
	{
		$result = new Result();
		$row = BankDetailTable::getRow([
			'select' => ['REQUISITE_ID' => 'ENTITY_ID'],
			'filter' => [
				'=ID' => $bankRequisiteId,
				'=ENTITY_TYPE_ID' => \CCrmOwnerType::Requisite,
			],
		]);
		$requisiteId = (int)($row['REQUISITE_ID'] ?? 0);
		if ($requisiteId <= 0)
		{
			return $result->addError(new Error('The bank requisite was not found.', 'CRM_BANK_REQUISITE_NOT_FOUND'));
		}

		return (new RequisiteProvider($userId))->resolveRequisiteOwner($requisiteId, $userId);
	}
}
