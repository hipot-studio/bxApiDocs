<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeRecord;
use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeType;
use Bitrix\Disk\Internal\Service\Sync\Journal\TombstoneReason;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * One record of the journal as the client reads it (DTO-03).
 *
 * Two things are reported here and they are not the same thing. A change of an object says what the
 * object now holds and carries an operation - it is either alive and to be re-read, or gone and to be
 * dropped. A change of access carries no operation at all: nothing happened to any object, a boundary
 * of visibility moved, and the client is told where to look again.
 *
 * Which fields are present is the shape of a record rather than a property of this class, so the
 * three constructors below are the only way in - the same three the writer publishes by
 * ({@see ChangeRecord}). A tombstone with a scope of access, or a change of access with an operation,
 * is not merely unlikely: it cannot be built. Every property is readonly for the same reason, so a
 * record cannot be reshaped after it was built either.
 *
 * A field that does not apply is absent from the answer, never present and empty: the property is
 * left uninitialised and {@see Dto::toArray()} drops the key. An empty value would be worse than
 * silence - a client would take it for an answer and act on it.
 *
 * Property order is the order of the published answer.
 */
final class ChangeDto extends Dto
{
	/** What the client orders its reading by. Opaque to it, and the position it continues from. */
	public readonly string $id;

	public readonly ChangeType $type;

	public readonly DateTime $occurredAt;

	/**
	 * The kind and the identifier of the real object the record is about, one of
	 * {@see ChangeRecord::RESOURCE_FILE} and {@see ChangeRecord::RESOURCE_FOLDER}. Both are absent
	 * from a change of access over the whole access of a user, which names no object at all.
	 */
	public readonly string $resourceType;

	public readonly int $resourceId;

	/** Absent from a record a background mechanism published: nobody performed it. */
	public readonly int $authorId;

	/**
	 * The fields the mutation touched, in the names of the card and not of the columns behind it, as
	 * they were fixed at publication. Absent where the publication recorded no list: the client
	 * re-reads the whole card and loses nothing.
	 *
	 * @var string[]
	 */
	public readonly array $changedFields;

	/**
	 * {@see ChangeRecord::OPERATION_UPDATE} or {@see ChangeRecord::OPERATION_TOMBSTONE}, and absent
	 * from a change of access. There is no third value: a client tells the shapes apart by the type
	 * of the change, and a type it does not know it skips anyway.
	 */
	public readonly string $operation;

	/** Only with a tombstone. What the client does is the same either way; support needs the reason. */
	public readonly TombstoneReason $tombstoneReason;

	/** Only with a change of access. */
	public readonly AccessScopeDto $accessScope;

	/**
	 * The object as it stood when the record was published, for a reader who could see it then.
	 * Absent otherwise - and a tombstone and a change of access carry no card at all, which is what
	 * makes them reach a client that cannot see the object any more.
	 */
	public readonly ObjectCardDto $card;

	/**
	 * The object is there and is to be re-read.
	 *
	 * @param string[]|null $changedFields
	 * @throws ArgumentOutOfRangeException
	 */
	public static function objectChange(
		string $id,
		ChangeType $type,
		DateTime $occurredAt,
		string $resourceType,
		int $resourceId,
		?int $authorId = null,
		?array $changedFields = null,
		?ObjectCardDto $card = null,
	): self
	{
		self::assertUpdateType($type);
		self::assertResourceType($resourceType);

		$change = self::of($id, $type, $occurredAt);
		$change->resourceType = $resourceType;
		$change->resourceId = $resourceId;
		$change->operation = ChangeRecord::OPERATION_UPDATE;
		if ($authorId !== null)
		{
			$change->authorId = $authorId;
		}

		if ($changedFields !== null)
		{
			$change->changedFields = array_values($changedFields);
		}

		if ($card !== null)
		{
			$change->card = $card;
		}

		return $change;
	}

	/**
	 * The object left the sight of the addressee and what the client holds is to be dropped.
	 *
	 * @throws ArgumentOutOfRangeException
	 */
	public static function tombstone(
		string $id,
		DateTime $occurredAt,
		string $resourceType,
		int $resourceId,
		TombstoneReason $reason,
		?int $authorId = null,
	): self
	{
		self::assertResourceType($resourceType);

		$change = self::of($id, ChangeType::Deleted, $occurredAt);
		$change->resourceType = $resourceType;
		$change->resourceId = $resourceId;
		$change->operation = ChangeRecord::OPERATION_TOMBSTONE;
		$change->tombstoneReason = $reason;
		if ($authorId !== null)
		{
			$change->authorId = $authorId;
		}

		return $change;
	}

	/**
	 * A boundary of visibility moved and the reader is to look again within the scope.
	 *
	 * The resource repeats the object the scope starts from, and the two are present or absent
	 * together: a scope over the whole access of a user names no object, every other one is worthless
	 * without it. No author is published - the boundaries of visibility move as a consequence of
	 * other work, and the journal records nobody for them.
	 *
	 * @param string|null $resourceType the kind of the object the scope starts from, and null only
	 *        for the scope over the whole access
	 * @throws ArgumentOutOfRangeException
	 */
	public static function accessChange(
		string $id,
		DateTime $occurredAt,
		AccessScopeDto $scope,
		?string $resourceType = null,
	): self
	{
		if ($scope->namesObject() !== ($resourceType !== null))
		{
			throw new ArgumentOutOfRangeException('resourceType');
		}

		if ($resourceType !== null)
		{
			self::assertResourceType($resourceType);
		}

		$change = self::of($id, ChangeType::AccessChanged, $occurredAt);
		$change->accessScope = $scope;
		if ($resourceType !== null)
		{
			$change->resourceType = $resourceType;
			$change->resourceId = $scope->rootId;
		}

		return $change;
	}

	private static function of(string $id, ChangeType $type, DateTime $occurredAt): self
	{
		$change = new self();
		$change->id = $id;
		$change->type = $type;
		$change->occurredAt = $occurredAt;

		return $change;
	}

	/**
	 * The two types that have shapes of their own are refused here. Everything else an object goes
	 * through - it appeared, it changed, it moved, it came back, it entered an interaction set - is
	 * the same record with a different name on it.
	 *
	 * @throws ArgumentOutOfRangeException
	 */
	private static function assertUpdateType(ChangeType $type): void
	{
		if ($type === ChangeType::Deleted || $type === ChangeType::AccessChanged)
		{
			throw new ArgumentOutOfRangeException('type');
		}
	}

	/**
	 * @throws ArgumentOutOfRangeException
	 */
	private static function assertResourceType(string $resourceType): void
	{
		if (!in_array($resourceType, [ChangeRecord::RESOURCE_FILE, ChangeRecord::RESOURCE_FOLDER], true))
		{
			throw new ArgumentOutOfRangeException('resourceType');
		}
	}
}
