<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeRecord;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The boundary of visibility a change of access moved (part of DTO-03).
 *
 * A change of access says nothing about what an object holds - it says where the reader is to look
 * again. The scope is that instruction: the object it starts from, if there is one, and how far it
 * reaches from there.
 *
 * The whole access of a user is the one kind that names no object: what stopped being visible is
 * exactly what no query of that user returns any more, so there is nothing to hang the boundary on
 * and {@see $rootId} is left uninitialised - absent from the answer rather than present and empty.
 *
 * Whether the descendants are reached follows from the kind and is published beside it because that
 * is what a client acts on: it re-reads a subtree or a single object without having to know the
 * vocabulary of the kinds. The journal keeps the same pair in two columns and the two agree by the
 * only path that writes them, {@see ChangeRecord::accessChange()}.
 *
 * Built through the three constructors below, so a scope that names no object while carrying a root
 * - or the reverse - cannot be assembled at all. Property order is the order of the published answer.
 */
final class AccessScopeDto extends Dto
{
	public const KIND_OBJECT = ChangeRecord::SCOPE_OBJECT;
	public const KIND_SUBTREE = ChangeRecord::SCOPE_SUBTREE;

	/**
	 * The journal keeps this kind as {@see ChangeRecord::SCOPE_ALL_ACCESS}; the answer spells it the
	 * way the contract spells every other name.
	 */
	public const KIND_ALL_ACCESS = 'allAccess';

	public readonly string $kind;

	/** The object the boundary starts from. Absent for the whole access of a user. */
	public readonly int $rootId;

	public readonly bool $affectsDescendants;

	public static function object(int $rootId): self
	{
		return self::of(self::KIND_OBJECT, $rootId, false);
	}

	public static function subtree(int $rootId): self
	{
		return self::of(self::KIND_SUBTREE, $rootId, true);
	}

	public static function allAccess(): self
	{
		return self::of(self::KIND_ALL_ACCESS, null, false);
	}

	/**
	 * The scope as the journal stored it, in the names the answer publishes.
	 *
	 * @throws ArgumentOutOfRangeException
	 */
	public static function ofJournalScope(string $scope, ?int $rootObjectId): self
	{
		if (($scope === ChangeRecord::SCOPE_ALL_ACCESS) !== ($rootObjectId === null))
		{
			throw new ArgumentOutOfRangeException('rootObjectId');
		}

		return match ($scope)
		{
			ChangeRecord::SCOPE_OBJECT => self::object((int)$rootObjectId),
			ChangeRecord::SCOPE_SUBTREE => self::subtree((int)$rootObjectId),
			ChangeRecord::SCOPE_ALL_ACCESS => self::allAccess(),
			default => throw new ArgumentOutOfRangeException('scope'),
		};
	}

	public function namesObject(): bool
	{
		return $this->kind !== self::KIND_ALL_ACCESS;
	}

	private static function of(string $kind, ?int $rootId, bool $affectsDescendants): self
	{
		$scope = new self();
		$scope->kind = $kind;
		if ($rootId !== null)
		{
			$scope->rootId = $rootId;
		}
		$scope->affectsDescendants = $affectsDescendants;

		return $scope;
	}
}
