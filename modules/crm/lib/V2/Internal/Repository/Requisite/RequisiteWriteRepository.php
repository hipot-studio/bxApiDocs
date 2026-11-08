<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Requisite;

use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteWriteData;
use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteWriteTarget;
use Bitrix\Crm\V2\Internal\Exception\Requisite\StorageRefusedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Data\Result;
use Bitrix\Main\SystemException;

/**
 * The single boundary the new layer writes a requisite through: one row inserted, updated or erased at a
 * time, plus the read of what a write has to know about the row before it can decide anything
 * ({@see self::findWriteTarget()}).
 *
 * **What this class does not do.** It checks no permission and opens no transaction. Permissions belong
 * to the domain ({@see \Bitrix\Crm\V2\Internal\Access\Requisite\RequisitePermissions}), and no line
 * of the write opens a transaction at all - not this boundary and not the operation above it
 * ({@see \Bitrix\Crm\V2\Internal\Service\Requisite\RequisiteWriteService}), which is why a refusal
 * of one call leaves the calls before it written. It validates nothing beyond the shape of its own
 * arguments either: what a field may hold is the business of the validator of the domain, which runs
 * before any of this.
 *
 * **How it refuses.** By exception, always. The table answers a validation error with a failed
 * {@see Result} and a failure of the query with a thrown exception, and both leave here as a throw: a
 * caller must never be able to read success out of a value it forgot to check, and the operation above
 * classifies one kind of refusal rather than two. The text of the refusal is carried over verbatim - the
 * classifier of the domain reads it and never forwards it.
 *
 * Owners are not narrowed here, unlike in {@see RequisiteRepository}: legacy lets a requisite belong to
 * a lead or a deal, and the facade line has to keep writing such rows. Which owners the group answers
 * for is decided a layer up, before the write.
 *
 * @internal
 */
final class RequisiteWriteRepository
{
	/** The letters the boolean columns of `b_crm_requisite` keep. */
	private const STORED_TRUE = 'Y';
	private const STORED_FALSE = 'N';

	/**
	 * The identifier of the row the storage created.
	 *
	 * @param array<string, mixed> $userFieldValues see {@see self::toRow()}.
	 * @throws SystemException the storage refused the row.
	 * @throws ArgumentException a field the storage has no column for.
	 */
	public function insert(RequisiteWriteData $fields, array $userFieldValues = []): int
	{
		$result = RequisiteTable::add(self::toRow($fields, $userFieldValues));
		if (!$result->isSuccess())
		{
			throw self::refusal('insert a requisite', $result);
		}

		$id = (int)$result->getId();
		if ($id <= 0)
		{
			// Nothing downstream can work with a row it cannot address, and the row is already written:
			// the caller has to undo the write rather than carry on with a zero.
			throw new SystemException('The storage inserted a requisite without answering its identifier');
		}

		return $id;
	}

	/**
	 * Writes the fields of $fields and leaves every other column of the row alone.
	 *
	 * Says nothing about the row existing: the storage updates the rows it finds, and a call for an
	 * identifier that is not there writes nothing and refuses nothing. Existence is answered above this
	 * boundary on either line: by {@see self::findWriteTarget()} on the published one, which calls it
	 * before anything else, and by the read the compatibility facade performs for its own reasons on the
	 * other, which hands the row it read down rather than having it read again.
	 *
	 * @param array<string, mixed> $userFieldValues see {@see self::toRow()}.
	 * @throws SystemException the storage refused the row.
	 * @throws ArgumentException a non-positive identifier, an empty set of fields, or a field the
	 *         storage has no column for.
	 */
	public function update(int $id, RequisiteWriteData $fields, array $userFieldValues = []): void
	{
		self::requireIdentifier($id);

		if ($fields->isEmpty())
		{
			// A write of nothing is a caller that has not decided what it is doing: legacy answers such a
			// call by touching the modification mark alone, and that is exactly the behaviour the group
			// stops. Refusing here keeps the decision where it belongs - above this boundary.
			throw new ArgumentException('A requisite update needs at least one field to write', 'fields');
		}

		$result = RequisiteTable::update($id, self::toRow($fields, $userFieldValues));
		if (!$result->isSuccess())
		{
			throw self::refusal('update a requisite', $result);
		}
	}

	/**
	 * Erases the row and nothing around it: the addresses, the bank details and the links of a requisite
	 * are erased by the operation that owns the whole write, in the order it decides.
	 *
	 * @throws SystemException the storage refused to erase the row.
	 * @throws ArgumentException a non-positive identifier.
	 */
	public function delete(int $id): void
	{
		self::requireIdentifier($id);

		$result = RequisiteTable::delete($id);
		if (!$result->isSuccess())
		{
			throw self::refusal('delete a requisite', $result);
		}
	}

	/**
	 * The owner and the preset of the requisite, or `null` when there is no such row - which is the answer
	 * a non-positive identifier gets as well, because there is no such row either. A write of an existing
	 * requisite asks its existence here too, rather than through a read of its own.
	 *
	 * Narrow on purpose and meant to stay narrow: {@see RequisiteWriteTarget} names the three values a
	 * write reads and says what widening the read costs. It is not the answer to "what does the row hold",
	 * and nothing of it is ever handed back to {@see self::update()}.
	 */
	public function findWriteTarget(int $id): ?RequisiteWriteTarget
	{
		if ($id <= 0)
		{
			return null;
		}

		$ownerTypeIdColumn = RequisiteColumnMap::COLUMN_BY_FIELD[Requisite::FIELD_OWNER_TYPE_ID];
		$ownerIdColumn = RequisiteColumnMap::COLUMN_BY_FIELD[Requisite::FIELD_OWNER_ID];
		$presetIdColumn = RequisiteColumnMap::COLUMN_BY_FIELD[Requisite::FIELD_PRESET_ID];

		$row = RequisiteTable::query()
			->setSelect([$ownerTypeIdColumn, $ownerIdColumn, $presetIdColumn])
			->where(RequisiteColumnMap::COLUMN_BY_FIELD[Requisite::FIELD_ID], $id)
			->fetch()
		;

		return is_array($row) ? self::writeTargetOf($row) : null;
	}

	/**
	 * @param array<string, mixed> $row the three columns of the row, however they were read.
	 */
	private static function writeTargetOf(array $row): RequisiteWriteTarget
	{
		$columnByField = RequisiteColumnMap::COLUMN_BY_FIELD;

		// An empty cell answers zero, as the set of the write answered it before: a row of legacy may well
		// have no owner and no preset, and the operations of the write read a zero as their absence.
		return new RequisiteWriteTarget(
			(int)($row[$columnByField[Requisite::FIELD_OWNER_TYPE_ID]] ?? 0),
			(int)($row[$columnByField[Requisite::FIELD_OWNER_ID]] ?? 0),
			(int)($row[$columnByField[Requisite::FIELD_PRESET_ID]] ?? 0),
		);
	}

	/**
	 * @param array<string, mixed> $userFieldValues values of columns that are not of this area: the user
	 *        fields the portal adds to a requisite, which the table writes itself, and - on the
	 *        compatibility line only - whatever else a legacy caller named, which the table silently
	 *        drops. They are passed on exactly as they came: what the storage recognizes among them is
	 *        its own business, and normalizing a value of a field the area does not declare would mean
	 *        guessing its type. A name the area does declare is taken from $fields instead, so the two
	 *        never overlap.
	 * @return array<string, mixed> the row in the vocabulary of the storage.
	 * @throws ArgumentException
	 */
	private static function toRow(RequisiteWriteData $fields, array $userFieldValues = []): array
	{
		$row = [];
		foreach ($fields->toArray() as $field => $value)
		{
			$row[RequisiteColumnMap::requireWriteColumn($field)] = is_bool($value)
				? ($value ? self::STORED_TRUE : self::STORED_FALSE)
				: $value
			;
		}

		return $row + $userFieldValues;
	}

	/**
	 * @throws ArgumentException
	 */
	private static function requireIdentifier(int $id): void
	{
		if ($id <= 0)
		{
			throw new ArgumentException(sprintf('`%d` is not an identifier of a requisite', $id), 'id');
		}
	}

	/**
	 * The refusal of the storage, described for whoever logs it and carried whole for whoever has to
	 * forward it: {@see StorageRefusedException} keeps the errors themselves next to the description,
	 * because the compatibility line of the write hands them to its consumers unchanged.
	 */
	private static function refusal(string $operation, Result $result): SystemException
	{
		$refusals = $result->getErrors();

		$messages = [];
		foreach ($refusals as $error)
		{
			$message = trim((string)$error->getMessage());
			if ($message !== '')
			{
				$messages[] = $message;
			}
		}

		return new StorageRefusedException(
			sprintf('The storage refused to %s: %s', $operation, implode('; ', $messages)),
			$refusals,
		);
	}
}
