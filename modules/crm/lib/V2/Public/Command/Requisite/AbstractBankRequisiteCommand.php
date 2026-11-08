<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Requisite;

use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteError;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\ValidationService;

abstract class AbstractBankRequisiteCommand implements CommandInterface
{
	private Scope $scope = Scope::Manual;

	abstract protected function execute(): Result;

	public function run(): Result
	{
		return $this->execute();
	}

	public function getScope(): Scope
	{
		return $this->scope;
	}

	public function setScope(Scope $scope): static
	{
		$this->scope = $scope;

		return $this;
	}

	protected static function validationError(string $field, string $message): Result
	{
		return (new Result())->addError(
			new Error($message, BankRequisiteError::VALIDATION, ['field' => $field]),
		);
	}

	protected function getValidationService(): ValidationService
	{
		/** @var ValidationService $validationService */
		$validationService = ServiceLocator::getInstance()->get('main.validation.service');

		return $validationService;
	}
}
