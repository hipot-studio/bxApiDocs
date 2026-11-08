<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Requisite;

use Bitrix\Crm\V2\Internal\Service\Requisite\RequisiteWriteService;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\RequisiteError;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * What every write of the requisite domain has in common: the user it is performed as, the scope it is
 * performed in, and the shape of its answer.
 *
 * A command is run once through {@see self::run()} and always answers a {@see Result}. A refusal a caller
 * can act on is a failed result carrying {@see RequisiteError} codes, and the phrase of each is put on
 * here: the domain refuses by code and leaves the wording to the published enumeration. More than one
 * refusal may arrive at once - a write reports every field it refused rather than the first of them - and
 * each refusal of a field names that field under `field` in {@see Error::getCustomData()}.
 *
 * A misuse of the contract itself is thrown rather than answered: a requisite that does not say which
 * owner it is for is an {@see ArgumentException}, the way a category that does not say which entity type
 * it belongs to already is ({@see \Bitrix\Crm\V2\Public\Command\Category\AbstractCategoryCommand}). It is
 * not the only exception a caller has to be ready for: what follows a successful write is outside the
 * result too, and it has a section of its own below.
 *
 * PERMISSIONS. Every command checks them and there is no way to ask it not to. The right to write a
 * requisite is the right over its owner, and it is resolved for the user the command was built with,
 * never for the global current user. A user who may not reach the record is told the record is not there
 * ({@see RequisiteError::REQUISITE_NOT_FOUND}, {@see RequisiteError::OWNER_NOT_FOUND}): telling the two
 * apart would let a client learn by trying which counterparties a portal keeps.
 *
 * SCOPE. Where the write came from ({@see self::setScope()}), defaulting to {@see Scope::Manual}. It says
 * nothing about what the write may do - the permissions above are checked whatever it is set to - and it
 * is the same statement a command of the pipeline domain carries.
 *
 * ADDRESSING. A requisite is addressed by its identifier and a new one by its owner. Both are arguments
 * of a command, or the address fields of the requisite it is handed ({@see Requisite::setOwnerId()},
 * {@see Requisite::setOwnerTypeId()}), and neither is ever content of a write: no command of this group
 * moves a requisite from one owner to another. A change and a deletion state on top of that the type of
 * owner they expect to find, and a requisite of another type is answered as an absent one
 * ({@see UpdateRequisiteCommand}).
 *
 * WHAT A WRITE CARRIES. The fields a setter was called on and only them, each with the value it was given
 * - a `null` among them included. A `null` on a named field empties the cell rather than dropping out of
 * the write: the mark, and not the value, is what says the caller named the field. That is the opposite of
 * what a `null` means in the pipeline domain, and it is what lets a client clear a legal detail it filled
 * in earlier.
 *
 * A requisite handed to a write that succeeded comes back carrying no changes of its own: the marks are
 * dropped and the values stay, so the same object can be given to the next write without repeating this
 * one.
 *
 * A WRITE IS NOT ATOMIC. No command wraps its write in a transaction, and none ever has: a write of a
 * requisite is a sequence of steps performed one after the other, and a refusal partway through leaves the
 * steps before it done. A deletion is where that shows: the links of a requisite, its addresses and its
 * bank details are erased before its row, so a storage that refuses the row answers
 * {@see RequisiteError::REQUISITE_OPERATION_FAILED} for a requisite that has already lost all three.
 * Nothing puts them back. This is what a write of a requisite has always done - the same steps in the same
 * order reach the same end through `\Bitrix\Crm\EntityRequisite` - and the two doors into the write failing
 * alike is the point.
 *
 * NOTHING HOLDS THE REQUISITE STILL. The right to write is decided over the owner the requisite has when
 * the command reads it, and the write that follows goes by the identifier alone. A requisite moved to
 * another owner in between - which `\Bitrix\Crm\EntityRequisite::transferOwnership()` does to every
 * requisite of a record the recycle bin takes in and gives back - is written or erased all the same, under
 * an owner nobody was asked about. A caller that cannot tolerate that has to keep the record still itself.
 *
 * WHAT FOLLOWS THE WRITE IS OUTSIDE THE RESULT. Once the row is written the domain rebuilds the duplicate
 * indexes of its owner, drops the tagged cache of the requisite selector and sends the event of the
 * domain, and none of that is guarded: a duplicate index that cannot be rebuilt, a cache that cannot be
 * dropped or a handler of the event belonging to another module that throws reaches the caller as an
 * exception, with the row already written. What that costs a caller is the part worth knowing: a creation
 * whose effect refused looks like a failed request, so a client that retries it safely creates a second
 * requisite. This is behaviour a write of a requisite has always had rather than a decision of this
 * layer - the effects have always run unguarded and behind the row - and keeping it is deliberate: the
 * alternative is one failure answered two ways depending on which door the write came through. Three
 * tests of the group hold it in place. So a caller that cares about a write it cannot see the answer to
 * catches the exception as well as reading the refusal.
 */
abstract class AbstractRequisiteCommand implements CommandInterface
{
	private Scope $scope = Scope::Manual;

	public function __construct(
		private readonly int $userId,
	)
	{
	}

	abstract protected function execute(): Result;

	public function run(): Result
	{
		return self::withPhrases($this->execute());
	}

	public function getUserId(): int
	{
		return $this->userId;
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

	/**
	 * The type of the owner a requisite is created for.
	 *
	 * @throws ArgumentException The requisite names no owner type. A type that names no owner of the CRM,
	 * and one whose records have no requisites, are not refused here - they are the absence of an owner
	 * ({@see RequisiteError::OWNER_NOT_FOUND}), which is what a caller acts on.
	 */
	protected static function ownerTypeIdOf(Requisite $requisite): int
	{
		$ownerTypeId = $requisite->getOwnerTypeId();
		if ($ownerTypeId === null)
		{
			throw new ArgumentException('A requisite of a creation must name the type of its owner.', 'requisite');
		}

		return $ownerTypeId;
	}

	/**
	 * The record a requisite is created for.
	 *
	 * @throws ArgumentException The requisite names no owner.
	 */
	protected static function ownerIdOf(Requisite $requisite): int
	{
		$ownerId = $requisite->getOwnerId();
		if ($ownerId === null)
		{
			throw new ArgumentException('A requisite of a creation must name its owner.', 'requisite');
		}

		return $ownerId;
	}

	/**
	 * The fields the write is to carry - the ones a setter was called on and only them, so that a field
	 * left alone keeps its stored value.
	 *
	 * Values travel as they are, `null` included: a field named and left empty is a cell to clear. Which
	 * of the named fields the preset of the requisite accepts is not decided here - a field it does not is
	 * refused naming that field rather than dropped on the way.
	 *
	 * @return array<string, int|bool|string|null>
	 */
	protected static function fieldsToWrite(Requisite $requisite): array
	{
		return $requisite->getChangedFieldValues();
	}

	/**
	 * The answer of a creation: an empty successful {@see Result}, or the refusal as it came.
	 *
	 * The success is reported through the requisite the caller handed in - it receives the identifier the
	 * new record was written under - rather than through the data of the result, which would publish a key
	 * of the layer below as part of this contract. It is the way the pipeline domain already reports a
	 * creation ({@see \Bitrix\Crm\V2\Public\Command\Category\AddCategoryCommand}).
	 */
	protected static function applyCreation(Requisite $requisite, Result $result): Result
	{
		if (!$result->isSuccess())
		{
			return $result;
		}

		$requisite->setId((int)$result->getData()[RequisiteWriteService::DATA_KEY_ID]);
		$requisite->resetChangedFields();

		return new Result();
	}

	/**
	 * The answer of a change: the empty successful {@see Result} it came as, or the refusal it came as.
	 *
	 * Nothing is read back onto the requisite - a change writes what it was given and the rest of the row
	 * is left alone - so all the requisite gets is the dropping of its marks.
	 */
	protected static function applyChange(Requisite $requisite, Result $result): Result
	{
		if ($result->isSuccess())
		{
			$requisite->resetChangedFields();
		}

		return $result;
	}

	/**
	 * The refusals of the domain as a caller receives them: the same codes, each with the phrase of its
	 * case and whatever the refusal names. An error the enumeration does not know is passed on untouched
	 * rather than replaced by a wrong phrase.
	 */
	private static function withPhrases(Result $result): Result
	{
		if ($result->isSuccess())
		{
			return $result;
		}

		$published = new Result();
		foreach ($result->getErrors() as $error)
		{
			$published->addError(self::phraseOf($error));
		}

		return $published;
	}

	private static function phraseOf(Error $error): Error
	{
		$case = RequisiteError::tryFrom((string)$error->getCode());

		return $case?->toError($error->getCustomData() ?? []) ?? $error;
	}
}
