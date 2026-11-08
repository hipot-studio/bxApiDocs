<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Requisite;

use Bitrix\Crm\V2\Internal\Service\Requisite\BankRequisiteService;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteField;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\NotEmpty;

final class UpdateBankRequisiteCommand extends AbstractBankRequisiteCommand
{
	/** @var string[] */
	#[NotEmpty(groups: ['update'])]
	private readonly array $fields;

	/** @param string[] $modifiedFields */
	public function __construct(
		private readonly EntityType $expectedOwnerType,
		private readonly BankRequisite $bankRequisite,
		array $modifiedFields,
		private readonly int $userId,
	)
	{
		$this->fields = array_values(array_unique($modifiedFields));
	}

	/** @return string[] */
	public function getModifiedFields(): array
	{
		return $this->fields;
	}

	protected function execute(): Result
	{
		$validationResult = $this->getValidationService()->validate($this->bankRequisite, 'update');
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}
		if ($this->bankRequisite->getRequisiteId() !== null)
		{
			return self::validationError('requisiteId', 'A bank requisite cannot be moved to another requisite.');
		}
		if ($this->bankRequisite->getTemplate() !== null)
		{
			return self::validationError('template', 'A write must not provide a bank requisite template.');
		}
		$validationResult = $this->getValidationService()->validate($this, 'update');
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$allowed = ['name', 'comments', ...array_column(BankRequisiteField::cases(), 'value')];
		foreach ($this->fields as $field)
		{
			if (!in_array($field, $allowed, true))
			{
				return self::validationError($field, 'The bank requisite field cannot be modified.');
			}
		}
		if (in_array('name', $this->fields, true))
		{
			$validationResult = $this->getValidationService()->validate($this->bankRequisite, 'updateName');
			if (!$validationResult->isSuccess())
			{
				return $validationResult;
			}
		}

		return (new BankRequisiteService($this->userId))->update(
			$this->expectedOwnerType,
			$this->bankRequisite,
			$this->fields,
		);
	}
}
