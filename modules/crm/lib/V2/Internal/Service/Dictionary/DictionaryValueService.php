<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Dictionary;

use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryError;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryField;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryFilter;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionarySort;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryType;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryValue;
use Bitrix\Crm\V2\Internal\Exception\Dictionary\DictionaryValueGoneException;
use Bitrix\Crm\V2\Internal\Repository\Dictionary\DictionaryValueRepository;
use Bitrix\Crm\V2\Internal\Repository\Dictionary\DictionaryValueRepositoryInterface;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Main\Error;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Result;

/**
 * The five operations on the values of the seven basic CRM dictionaries, and every decision of an
 * operation: the permission it needs, whether the value it addresses exists, which fields it may write
 * and what they may hold, and what a refusal of the storage boundary means.
 *
 * ONE ANSWER SHAPE. Every operation answers a {@see Result}: a success carries the value under
 * {@see DATA_KEY_VALUE} (a list under {@see DATA_KEY_VALUES}, a deletion nothing), a refusal carries
 * errors whose codes are {@see DictionaryError} and nothing else. Neither a legacy type nor a text of
 * the storage boundary leaves here - the boundary is answered for by
 * {@see StorageRefusalMapper}, and what it wrote is only ever logged.
 *
 * THE ORDER OF THE CHECKS IS THE SAME IN EVERY OPERATION: the permission, then the existence of the
 * value, then the rules of the fields, then the boundary, then the translation of its refusal. It is
 * the order the refusals have to come in: a caller with no permission learns nothing about what the
 * dictionary holds, and a rule of a field is not reported about a value that is not there.
 *
 * THE USER IS AN ARGUMENT, NEVER THE CURRENT ONE. This domain resolves no current user: an operation
 * says who it is performed as, and there is no way to ask it to skip the permission - configuring the
 * dictionaries of a portal has no system caller (the same rule as
 * {@see \Bitrix\Crm\V2\Public\Command\Category\AbstractCategoryCommand}).
 *
 * A VALUE CAN GO AWAY WHILE AN OPERATION RUNS. A change and an erasure read the value to answer whether
 * it exists and then hand the write to a boundary that addresses the row itself, and the two are not one
 * act: the boundary holds its own writers of that value to one at a time, but the value may be gone
 * before it is asked at all - erased by another request, or by anything else of the portal that writes
 * the same rows. The domain answers that with `VALUE_NOT_FOUND` ({@see valueGone()}) rather than letting
 * it out as a failure: by the time the write ran, the value the caller addressed was not there, which is
 * exactly what that refusal says.
 *
 * THE SCOPE OF A WRITE IS TAKEN AND NOT READ. Every write names the scope it is performed in
 * ({@see Scope}), and no decision of this domain looks at it today: the storage boundary knows of no
 * scope, no scope skips a permission or a rule of a field, and the answer is the same whichever one is
 * given. It is taken all the same, and by every write - a write of the funnel domain next door names its
 * scope the same way and with the same default - so that a caller states where a change of the
 * configuration of a portal came from. What it is there to be read for is a write that has to differ by
 * its source: a restriction that applies to callers outside the portal, or a side effect only a change
 * made by hand raises. Until such a rule exists this argument is a declaration and nothing more, and a
 * caller may not expect a scope to change what an operation does.
 *
 * @internal
 */
final class DictionaryValueService
{
	/** The value an operation is about, inside {@see Result::getData()}. */
	public const DATA_KEY_VALUE = 'value';

	/** The values a list answers with as a `list<DictionaryValue>`, inside {@see Result::getData()}. */
	public const DATA_KEY_VALUES = 'values';

	/**
	 * Longest identifier a value may be given, in characters. Checked here rather than left to the
	 * storage layer, which cuts a longer one down silently.
	 */
	private const ID_MAX_LENGTH = 50;

	/**
	 * What an identifier may not contain: the storage layer reads everything up to the first colon as
	 * the dictionary of the value and stores only the rest, so `'FOO:BAR'` would silently become
	 * `'BAR'`.
	 */
	private const ID_FORBIDDEN_CHARACTER = ':';

	/**
	 * The one identifier the storage layer reads as no identifier at all: it asks `empty()` of the one it
	 * was given ({@see \CCrmStatus::Add()}), and `'0'` answers that question the way an empty string does,
	 * so the value would be stored under a generated identifier and the caller would never be told.
	 */
	private const ID_READ_AS_ABSENT_BY_STORAGE = '0';

	/** Longest name a value may be given, in characters - the width of the column it is stored in. */
	private const NAME_MAX_LENGTH = 100;

	public function __construct(
		private readonly DictionaryValueRepositoryInterface $repository = new DictionaryValueRepository(),
		private readonly DictionaryAccess $access = new DictionaryAccess(),
		private readonly StorageRefusalMapper $refusalMapper = new StorageRefusalMapper(),
	)
	{
	}

	/**
	 * The value $id of the dictionary $type, read as $userId.
	 *
	 * A value the dictionary has not is `VALUE_NOT_FOUND`, and so is a value that exists only in another
	 * dictionary or only among the stages of a funnel.
	 */
	public function getById(DictionaryType $type, string $id, int $userId): Result
	{
		if (!$this->access->canRead($userId))
		{
			return self::refusedWith(DictionaryError::ACCESS_DENIED);
		}

		$value = $this->repository->getById($type, $id);
		if ($value === null)
		{
			return self::refusedWith(DictionaryError::VALUE_NOT_FOUND);
		}

		return self::answerWith($value);
	}

	/**
	 * The values of the dictionary $type the filter selects, read as $userId, in the order $sort asks
	 * for and cut to the page $pager asks for.
	 *
	 * Nothing asked for means the whole dictionary in the order it is kept in. A page limit of its own
	 * this domain has none: the limit is the platform one and belongs to the transport.
	 */
	public function getList(
		DictionaryType $type,
		int $userId,
		?DictionaryFilter $filter = null,
		?DictionarySort $sort = null,
		?PagerInterface $pager = null,
	): Result
	{
		if (!$this->access->canRead($userId))
		{
			return self::refusedWith(DictionaryError::ACCESS_DENIED);
		}

		return (new Result())->setData([
			self::DATA_KEY_VALUES => $this->repository->getList($type, $filter, $sort, $pager),
		]);
	}

	/**
	 * Creates a value of the dictionary $type out of $fields as $userId, and answers with the created
	 * value read back from storage.
	 *
	 * `name` is required and `id` and `sort` are not: an identifier left out - or given as an empty
	 * string - is generated, and a position left out is the one the storage layer gives a new value. The
	 * caller learns both from the value handed back. An identifier of blanks alone asks for neither and is
	 * refused, and so is `'0'`, which the storage layer would read as an identifier it was not given.
	 *
	 * $scope names where the write came from and is read nowhere - see the note of the class about it.
	 *
	 * @param array<string, mixed> $fields `id`, `name`, `sort` of {@see DictionaryField}
	 */
	public function add(
		DictionaryType $type,
		array $fields,
		int $userId,
		Scope $scope = Scope::Manual,
	): Result
	{
		if (!$this->access->canWrite($userId))
		{
			return self::refusedWith(DictionaryError::ACCESS_DENIED);
		}

		$refusals = self::refusalsOf($fields, isCreation: true);
		if ($refusals !== [])
		{
			return (new Result())->addErrors($refusals);
		}

		// The name is there: a creation that does not carry one is refused above, and so is an empty one.
		return $this->stored($this->repository->add(
			$type,
			self::givenId($fields),
			self::givenName($fields) ?? '',
			self::givenSort($fields),
		));
	}

	/**
	 * Changes the value $id of the dictionary $type by $fields as $userId, and answers with the changed
	 * value read back from storage.
	 *
	 * A field left out keeps what it holds, and $fields with nothing in it changes nothing at all: the
	 * permission and the existence of the value are still answered for, and the answer is the current
	 * value.
	 *
	 * The identifier is not among the fields a change may carry - it is the address of the value.
	 *
	 * $scope names where the write came from and is read nowhere - see the note of the class about it.
	 *
	 * @param array<string, mixed> $fields `name`, `sort` of {@see DictionaryField}
	 */
	public function update(
		DictionaryType $type,
		string $id,
		array $fields,
		int $userId,
		Scope $scope = Scope::Manual,
	): Result
	{
		if (!$this->access->canWrite($userId))
		{
			return self::refusedWith(DictionaryError::ACCESS_DENIED);
		}

		$current = $this->repository->getById($type, $id);
		if ($current === null)
		{
			return self::refusedWith(DictionaryError::VALUE_NOT_FOUND);
		}

		$refusals = self::refusalsOf($fields, isCreation: false);
		if ($refusals !== [])
		{
			return (new Result())->addErrors($refusals);
		}

		if ($fields === [])
		{
			return self::answerWith($current);
		}

		try
		{
			return $this->stored($this->repository->update(
				$type,
				$id,
				self::givenName($fields),
				self::givenSort($fields),
			));
		}
		catch (DictionaryValueGoneException)
		{
			return self::valueGone();
		}
	}

	/**
	 * Erases the value $id of the dictionary $type as $userId. A successful result carries no data.
	 *
	 * A value that ships with the product is never erased, and the refusal comes before the storage
	 * layer is asked - it would have erased it. Elements referring to the erased value are not looked
	 * at: the reference stays behind pointing at nothing, which is what this domain does today.
	 *
	 * $scope names where the write came from and is read nowhere - see the note of the class about it.
	 */
	public function delete(
		DictionaryType $type,
		string $id,
		int $userId,
		Scope $scope = Scope::Manual,
	): Result
	{
		if (!$this->access->canWrite($userId))
		{
			return self::refusedWith(DictionaryError::ACCESS_DENIED);
		}

		$current = $this->repository->getById($type, $id);
		if ($current === null)
		{
			return self::refusedWith(DictionaryError::VALUE_NOT_FOUND);
		}

		if ($current->isSystem)
		{
			return self::refusedWith(DictionaryError::SYSTEM_VALUE_NOT_DELETABLE);
		}

		try
		{
			return $this->refusalMapper->mapResult($this->repository->delete($type, $id));
		}
		catch (DictionaryValueGoneException)
		{
			return self::valueGone();
		}
	}

	/**
	 * The answer of a write: the refusal of the storage boundary turned into a refusal of this domain,
	 * or the stored value under the key of this domain.
	 */
	private function stored(Result $written): Result
	{
		$answer = $this->refusalMapper->mapResult($written);
		if (!$answer->isSuccess())
		{
			return $answer;
		}

		return self::answerWith($answer->getData()[DictionaryValueRepositoryInterface::DATA_KEY_VALUE]);
	}

	/**
	 * Every rule of the fields broken by $fields, as errors of this domain. All of them at once rather
	 * than the first one: a caller fixing a request wants the whole of what is wrong with it.
	 *
	 * @param array<string, mixed> $fields
	 * @return Error[]
	 */
	private static function refusalsOf(array $fields, bool $isCreation): array
	{
		return array_merge(
			self::writabilityRefusalsOf($fields, $isCreation),
			self::identifierRefusalsOf($fields, $isCreation),
			self::nameRefusalsOf($fields, $isCreation),
			self::sortRefusalsOf($fields),
		);
	}

	/**
	 * Fields $fields names that this domain does not write: `isSystem` in any operation, `id` in a
	 * change, and any name that is no field of a dictionary value at all. The last one names no field
	 * back - the key came from the caller and is not part of the vocabulary a client is answered in.
	 *
	 * @param array<string, mixed> $fields
	 * @return Error[]
	 */
	private static function writabilityRefusalsOf(array $fields, bool $isCreation): array
	{
		$refusals = [];
		foreach (array_keys($fields) as $name)
		{
			$field = DictionaryField::tryFrom((string)$name);
			if ($field === null)
			{
				$refusals[] = DictionaryError::FIELD_NOT_WRITABLE->toError();

				continue;
			}

			if ($field === DictionaryField::IsSystem || ($field === DictionaryField::Id && !$isCreation))
			{
				$refusals[] = DictionaryError::FIELD_NOT_WRITABLE->toError($field);
			}
		}

		return $refusals;
	}

	/**
	 * The identifier a creation was given, checked before anything is written: the storage layer would
	 * store a shortened one instead of refusing it.
	 *
	 * An empty string is not an identifier but the absence of one, and it is admitted as such. A value
	 * that is not a string at all - an explicit `null` above all - is a shape the field does not have.
	 *
	 * An identifier of blanks alone is neither of those: it is refused rather than read as the absence of
	 * one, and it is not trimmed either. The name is trimmed and the identifier is not, because the name
	 * is the caption of a value and the identifier is its address: a caption is safe to tidy up, an
	 * address stored other than as it was given addresses another value. An identifier that is not blank
	 * is stored exactly as it came.
	 *
	 * `'0'` is refused for the same reason and not stored either
	 * ({@see self::ID_READ_AS_ABSENT_BY_STORAGE}): the storage layer reads it as no identifier at all and
	 * generates one instead, so the value would answer to an address the caller never asked for. Storing
	 * it as given is not something this domain can arrange - the reading is made by the legacy facade the
	 * write goes through.
	 *
	 * @param array<string, mixed> $fields
	 * @return Error[]
	 */
	private static function identifierRefusalsOf(array $fields, bool $isCreation): array
	{
		$key = DictionaryField::Id->value;
		if (!$isCreation || !array_key_exists($key, $fields))
		{
			return [];
		}

		$id = $fields[$key];
		if (!DictionaryField::Id->acceptsValue($id))
		{
			return [DictionaryError::FIELD_VALUE_NOT_ALLOWED->toError(DictionaryField::Id)];
		}

		if ($id === '')
		{
			return [];
		}

		if (
			trim($id) === ''
			|| $id === self::ID_READ_AS_ABSENT_BY_STORAGE
			|| str_contains($id, self::ID_FORBIDDEN_CHARACTER)
			|| mb_strlen($id) > self::ID_MAX_LENGTH
		)
		{
			return [DictionaryError::VALUE_ID_NOT_ALLOWED->toError()];
		}

		return [];
	}

	/**
	 * The name, which a creation has to carry and a change may leave out. Blanks around it are not part
	 * of it - the storage layer stores it trimmed - so a name of blanks alone is an empty one, and it is
	 * refused in both operations: the storage layer would answer a creation with an error about a
	 * required field and would leave the old name of a change in place without a word.
	 *
	 * @param array<string, mixed> $fields
	 * @return Error[]
	 */
	private static function nameRefusalsOf(array $fields, bool $isCreation): array
	{
		$key = DictionaryField::Name->value;
		if (!array_key_exists($key, $fields))
		{
			return $isCreation ? [self::nameRefusal()] : [];
		}

		$name = $fields[$key];
		if (!DictionaryField::Name->acceptsValue($name))
		{
			return [self::nameRefusal()];
		}

		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH)
		{
			return [self::nameRefusal()];
		}

		return [];
	}

	/**
	 * The position, of which only the shape is this domain's business: a position of zero or less is
	 * replaced by the storage layer with a default one, which is what it does today.
	 *
	 * @param array<string, mixed> $fields
	 * @return Error[]
	 */
	private static function sortRefusalsOf(array $fields): array
	{
		$key = DictionaryField::Sort->value;
		if (!array_key_exists($key, $fields) || DictionaryField::Sort->acceptsValue($fields[$key]))
		{
			return [];
		}

		return [DictionaryError::FIELD_VALUE_NOT_ALLOWED->toError(DictionaryField::Sort)];
	}

	/** @param array<string, mixed> $fields */
	private static function givenId(array $fields): ?string
	{
		$id = (string)($fields[DictionaryField::Id->value] ?? '');

		// An identifier left out and an empty one are the same request: let the boundary make one up.
		return $id === '' ? null : $id;
	}

	/**
	 * The name as it is written, or `null` for a change that leaves it alone.
	 *
	 * @param array<string, mixed> $fields
	 */
	private static function givenName(array $fields): ?string
	{
		$key = DictionaryField::Name->value;

		return array_key_exists($key, $fields) ? trim((string)$fields[$key]) : null;
	}

	/**
	 * The position as it is written, or `null` for an operation that does not name one.
	 *
	 * @param array<string, mixed> $fields
	 */
	private static function givenSort(array $fields): ?int
	{
		$key = DictionaryField::Sort->value;

		return array_key_exists($key, $fields) ? (int)$fields[$key] : null;
	}

	private static function nameRefusal(): Error
	{
		return DictionaryError::FIELD_VALUE_NOT_ALLOWED->toError(DictionaryField::Name);
	}

	/**
	 * The answer for a value that was there when the operation read it and is not there any more.
	 *
	 * It is the same refusal a caller naming an identifier the dictionary never had gets, and on purpose:
	 * the two are one outcome for a client - the value it addressed is not in the dictionary - and telling
	 * them apart would publish the order in which the domain does its own reads. Two callers erasing one
	 * value at the same time are answered this way: one of them erases it, the other is told it is not
	 * there.
	 */
	private static function valueGone(): Result
	{
		return self::refusedWith(DictionaryError::VALUE_NOT_FOUND);
	}

	private static function refusedWith(DictionaryError $error): Result
	{
		return (new Result())->addError($error->toError());
	}

	private static function answerWith(DictionaryValue $value): Result
	{
		return (new Result())->setData([self::DATA_KEY_VALUE => $value]);
	}
}
