<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Requisite;

use Bitrix\Crm\V2\Internal\Service\Requisite\BankRequisiteService;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;

final class DeleteBankRequisiteCommand extends AbstractBankRequisiteCommand
{
	public function __construct(
		private readonly EntityType $expectedOwnerType,
		#[PositiveNumber]
		private readonly int $id,
		private readonly int $userId,
	)
	{
	}

	protected function execute(): Result
	{
		$validationResult = $this->getValidationService()->validate($this);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		return (new BankRequisiteService($this->userId))->delete($this->expectedOwnerType, $this->id);
	}
}
