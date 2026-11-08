<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Requisite;

use Bitrix\Crm\V2\Internal\Service\Requisite\ClientRequisiteService;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Entity\Requisite\ClientRequisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\ClientRequisiteError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Requisite\ClientRequisiteProvider;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\ValidationService;

/**
 * Chooses the requisite of the client in a CRM entity, together with the bank requisite beside it.
 *
 * The pair is written as a whole, so both identifiers are always given. Zero is a value of its own and
 * means "nothing chosen": a zero requisite clears the choice, and a zero bank requisite beside a
 * requisite clears the bank requisite alone. That is why the pair arrives as two integers rather than
 * as {@see ClientRequisite}, where `null` says only that a value was never set.
 *
 * The subject of every permission check is the user given to the constructor - choosing a requisite is
 * a change of the entity, and it follows the update right of that entity. Nothing here asks who the
 * current user is.
 *
 * The command checks the range of its own arguments and nothing else. Whether the pair may be chosen
 * at all is a question about the client of the entity, and it is answered where the client is known:
 * the refusals are the cases of {@see ClientRequisiteError}. A refused write leaves the stored pair as
 * it was.
 *
 * The answer carries the pair as it was written, under
 * {@see ClientRequisiteProvider::DATA_CLIENT_REQUISITE} - the same key a read answers with, so a
 * caller needs no second request to learn what the entity now holds.
 */
final class SetClientRequisiteCommand implements CommandInterface
{
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

	/** @return Result data: {@see ClientRequisiteProvider::DATA_CLIENT_REQUISITE} => {@see ClientRequisite} */
	public function run(): Result
	{
		$validationResult = $this->getValidationService()->validate($this);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		return (new ClientRequisiteService($this->userId))->set(
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
