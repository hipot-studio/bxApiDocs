<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Journal;

use Bitrix\Main\ArgumentOutOfRangeException;

/**
 * One record of the journal, together with the codes it is addressed to.
 *
 * The two shapes of a record are kept apart here rather than at the insert. A change of an object
 * carries an operation, a resource and the fields it touched; a change of access carries no operation
 * at all and instead carries the scope of the boundary that moved and whether it reaches the
 * descendants of that scope. Building a record through one of the three constructors below is what
 * makes a shape that mixes the two - a tombstone without a reason, a scope without an object, a scope
 * naming an object of no known kind - impossible rather than merely unlikely.
 *
 * The vocabulary of the columns that describe the shape lives here for the same reason: the writer is
 * the only code that publishes, so a value the writer does not name is a value the journal does not
 * contain.
 */
final readonly class ChangeRecord
{
	public const OPERATION_UPDATE = 'update';
	public const OPERATION_TOMBSTONE = 'tombstone';

	public const RESOURCE_FILE = 'file';
	public const RESOURCE_FOLDER = 'folder';

	/** The object named by the scope and nothing below it. */
	public const SCOPE_OBJECT = 'object';

	/** The object named by the scope and everything under it. */
	public const SCOPE_SUBTREE = 'subtree';

	/** Everything the addressee may see. The one scope that names no object. */
	public const SCOPE_ALL_ACCESS = 'all_access';

	/**
	 * @param string[] $accessCodes
	 * @param string[]|null $changedFields
	 */
	private function __construct(
		public ChangeType $changeType,
		public array $accessCodes,
		public ?string $operation = null,
		public ?string $resourceType = null,
		public ?int $resourceId = null,
		public ?int $authorId = null,
		public ?array $changedFields = null,
		public ?TombstoneReason $tombstoneReason = null,
		public ?string $accessScope = null,
		public ?int $scopeObjectId = null,
		public bool $affectsDescendants = false,
	)
	{
	}

	/**
	 * @param string[] $accessCodes
	 * @param string[]|null $changedFields
	 * @throws ArgumentOutOfRangeException
	 */
	public static function objectChange(
		ChangeType $changeType,
		string $resourceType,
		int $resourceId,
		array $accessCodes,
		?int $authorId = null,
		?array $changedFields = null,
	): self
	{
		self::assertResourceType($resourceType);

		return new self(
			changeType: $changeType,
			accessCodes: $accessCodes,
			operation: self::OPERATION_UPDATE,
			resourceType: $resourceType,
			resourceId: $resourceId,
			authorId: $authorId,
			changedFields: $changedFields,
		);
	}

	/**
	 * @param string[] $accessCodes
	 * @throws ArgumentOutOfRangeException
	 */
	public static function tombstone(
		string $resourceType,
		int $resourceId,
		TombstoneReason $reason,
		array $accessCodes,
		?int $authorId = null,
	): self
	{
		self::assertResourceType($resourceType);

		return new self(
			changeType: ChangeType::Deleted,
			accessCodes: $accessCodes,
			operation: self::OPERATION_TOMBSTONE,
			resourceType: $resourceType,
			resourceId: $resourceId,
			authorId: $authorId,
			tombstoneReason: $reason,
		);
	}

	/**
	 * @param string[] $accessCodes
	 * @throws ArgumentOutOfRangeException
	 */
	public static function accessChange(
		string $scope,
		?int $scopeObjectId,
		bool $affectsDescendants,
		array $accessCodes,
		?string $resourceType = null,
	): self
	{
		self::assertScope($scope, $scopeObjectId);
		self::assertResourceOfScope($scopeObjectId, $resourceType);

		return new self(
			changeType: ChangeType::AccessChanged,
			accessCodes: $accessCodes,
			resourceType: $resourceType,
			resourceId: $scopeObjectId,
			accessScope: $scope,
			scopeObjectId: $scopeObjectId,
			affectsDescendants: $affectsDescendants,
		);
	}

	public function isAccessChange(): bool
	{
		return $this->accessScope !== null;
	}

	/**
	 * @throws ArgumentOutOfRangeException
	 */
	private static function assertResourceType(string $resourceType): void
	{
		if (!in_array($resourceType, [self::RESOURCE_FILE, self::RESOURCE_FOLDER], true))
		{
			throw new ArgumentOutOfRangeException('resourceType');
		}
	}

	/**
	 * The whole access of a user is the one boundary that cannot be tied to an object: what stopped
	 * being visible is exactly what no query of that user returns any more. Every other scope is a
	 * boundary of a named object and is worthless without it.
	 *
	 * @throws ArgumentOutOfRangeException
	 */
	private static function assertScope(string $scope, ?int $scopeObjectId): void
	{
		if (!in_array($scope, [self::SCOPE_OBJECT, self::SCOPE_SUBTREE, self::SCOPE_ALL_ACCESS], true))
		{
			throw new ArgumentOutOfRangeException('scope');
		}

		if (($scope === self::SCOPE_ALL_ACCESS) !== ($scopeObjectId === null))
		{
			throw new ArgumentOutOfRangeException('scopeObjectId');
		}
	}

	/**
	 * A scope that names an object names the kind of that object with it, and the scope over the whole
	 * access names neither. The two travel together to the client, which is handed an identifier it is
	 * to look at again: an identifier of no known kind is one it has no way of reading back, so a
	 * record of that shape would be dropped on the way out rather than delivered.
	 *
	 * @throws ArgumentOutOfRangeException
	 */
	private static function assertResourceOfScope(?int $scopeObjectId, ?string $resourceType): void
	{
		if (($scopeObjectId === null) !== ($resourceType === null))
		{
			throw new ArgumentOutOfRangeException('resourceType');
		}

		if ($resourceType !== null)
		{
			self::assertResourceType($resourceType);
		}
	}
}
