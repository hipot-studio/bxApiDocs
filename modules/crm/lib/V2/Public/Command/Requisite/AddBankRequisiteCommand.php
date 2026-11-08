<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Requisite;

use Bitrix\Crm\V2\Internal\Service\Requisite\BankRequisiteService;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisite;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

final class AddBankRequisiteCommand extends AbstractBankRequisiteCommand
{
	public function __construct(
		private readonly EntityType $expectedOwnerType,
		private readonly BankRequisite $bankRequisite,
		private readonly int $userId,
		private readonly ?array $modifiedFields = null,
	)
	{
	}

	protected function execute(): Result
	{
		$validationResult = $this->getValidationService()->validate($this->bankRequisite, 'add');
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}
		if ($this->bankRequisite->getTemplate() !== null)
		{
			return self::validationError('template', 'A write must not provide a bank requisite template.');
		}

		return (new BankRequisiteService($this->userId))->add(
			$this->expectedOwnerType,
			$this->bankRequisite,
			$this->modifiedFields,
		);
	}
}
