<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Requisite;

use Bitrix\Crm\V2\Internal\Service\Requisite\MyCompanyRequisiteService;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Entity\Requisite\MyCompanyRequisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\MyCompanyRequisiteError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Requisite\MyCompanyRequisiteProvider;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\ValidationService;

/**
 * Chooses the requisite of the seller in a CRM entity - the requisite of the company the entity names
 * as "my company" - together with the bank requisite beside it.
 *
 * The pair is written as a whole, so both identifiers are always given. Zero is a value of its own and
 * means "nothing chosen": a zero requisite clears the choice, and a zero bank requisite beside a
 * requisite clears the bank requisite alone. That is why the pair arrives as two integers rather than
 * as {@see MyCompanyRequisite}, where `null` says only that a value was never set.
 *
 * The subject of every permission check is the user given to the constructor - choosing a requisite is
 * a change of the entity, and it follows the update right of that entity. Nothing here asks who the
 * current user is.
 *
 * The command checks the range of its own arguments and nothing else. Whether the pair may be chosen
 * at all is a question about the company the entity names as its own, and it is answered where that
 * company is known: the refusals are the cases of {@see MyCompanyRequisiteError}. A refused write
 * leaves the stored pair as it was.
 *
 * The answer carries the pair as it was written, under
 * {@see MyCompanyRequisiteProvider::DATA_MY_COMPANY_REQUISITE} - the same key a read answers with, so
 * a caller needs no second request to learn what the entity now holds.
 */
final class SetMyCompanyRequisiteCommand implements CommandInterface
{
	/** Reserved for the symmetry of the requisite commands: the run of this one does not read it. */
	private Scope $scope = Scope::Manual;

	/**
	 * @param int $requisiteId zero clears the choice
	 * @param int $bankRequisiteId zero clears the bank requisite alone
	 */
	public function __construct(
		private readonly EntityType $ownerType,
		#[PositiveNumber]
		private readonly int $ownerId,
		#[Min(0)]
		private readonly int $requisiteId,
		#[Min(0)]
		private readonly int $bankRequisiteId,
		private readonly int $userId,
	)
	{
	}

	/**
	 * @return Result data: {@see MyCompanyRequisiteProvider::DATA_MY_COMPANY_REQUISITE}
	 *                      => {@see MyCompanyRequisite}
	 */
	public function run(): Result
	{
		$validationResult = $this->getValidationService()->validate($this);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		return (new MyCompanyRequisiteService($this->userId))->set(
			$this->ownerType,
			$this->ownerId,
			$this->requisiteId,
			$this->bankRequisiteId,
		);
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

	private function getValidationService(): ValidationService
	{
		/** @var ValidationService $validationService */
		$validationService = ServiceLocator::getInstance()->get('main.validation.service');

		return $validationService;
	}
}
