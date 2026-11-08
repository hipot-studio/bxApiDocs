<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;

/**
 * Everything a provider is allowed to know about the operation it is validating, besides the activity itself.
 *
 * WHICH OPERATION IT IS, TOLD BY STRUCTURE. An update carries the state the activity had before it; an add
 * has nothing to carry. The two named constructors are therefore the only way to build a context, and
 * {@see self::isUpdate()} is an observation about the previous state rather than a flag that may disagree
 * with it. Legacy passed the same distinction as a string next to a field array where the previous state was
 * optional, and a caller that forgot it turned an update into something no provider could tell from an add.
 *
 * THE PREVIOUS STATE IS A TRIPLE, because a stored row is. The typed model holds the fields with meaning, the
 * payload holds the serialized ones, and the transit holds the storage columns neither of them owns - and
 * real providers read from all three: a description and an end time from the model, provider params from the
 * payload, the identifier of a workflow from the columns.
 *
 * THE COLUMNS OF THE OPERATION ARE WRITABLE, and that is the point of carrying them. A provider derives the
 * deadline of an activity from its start or its end time, and the deadline lives nowhere else: the model
 * treats it as read-only precisely because a provider is its only author. The same columns are read as well -
 * the identifier of a calendar event, of an associated entity, of an origin.
 *
 * TWO VALUES FOR ONE USER. The effective identifier is the one to act as - what the caller named, or the
 * current user when the caller named nobody. The supplied one is what the caller actually named, zero
 * included, and it is not interchangeable with the effective one: the chain that picks the executor of a task
 * on the other side of the integration with the module of tasks distinguishes "nobody was named" from "the
 * current user was named", and collapsing the two breaks the synchronization back into a task.
 *
 * @internal
 */
final class ValidationContext
{
	private function __construct(
		private readonly StorageFieldTransit $transit,
		private readonly int $userId,
		private readonly int $suppliedUserId,
		private readonly bool $initiatedByCalendar,
		private readonly bool $preserveCreationTime,
		private readonly ?Activity $previousActivity,
		private readonly ?ProviderPayload $previousPayload,
		private readonly ?StorageFieldTransit $previousTransit,
	)
	{
	}

	public static function forAdd(
		StorageFieldTransit $transit,
		int $userId,
		int $suppliedUserId = 0,
		bool $initiatedByCalendar = false,
		bool $preserveCreationTime = false,
	): self
	{
		return new self(
			$transit,
			$userId,
			$suppliedUserId,
			$initiatedByCalendar,
			$preserveCreationTime,
			null,
			null,
			null,
		);
	}

	/**
	 * The creation time is preserved on an add only, so an update takes no such flag.
	 */
	public static function forUpdate(
		StorageFieldTransit $transit,
		int $userId,
		Activity $previousActivity,
		ProviderPayload $previousPayload,
		StorageFieldTransit $previousTransit,
		int $suppliedUserId = 0,
		bool $initiatedByCalendar = false,
	): self
	{
		return new self(
			$transit,
			$userId,
			$suppliedUserId,
			$initiatedByCalendar,
			false,
			$previousActivity,
			$previousPayload,
			$previousTransit,
		);
	}

	public function isAdd(): bool
	{
		return $this->previousActivity === null;
	}

	public function isUpdate(): bool
	{
		return $this->previousActivity !== null;
	}

	/**
	 * The storage columns of this operation. Mutable on purpose: a deadline derived by the provider is put
	 * here, and this is the only place it can be put.
	 */
	public function getTransit(): StorageFieldTransit
	{
		return $this->transit;
	}

	/**
	 * The user to act as.
	 */
	public function getUserId(): int
	{
		return $this->userId;
	}

	/**
	 * The user the caller named, zero when it named nobody.
	 */
	public function getSuppliedUserId(): int
	{
		return $this->suppliedUserId;
	}

	/**
	 * Whether the operation was started by the calendar rather than by CRM. A provider synchronized with a
	 * calendar event uses it to keep from writing back what the calendar has just written.
	 */
	public function isInitiatedByCalendar(): bool
	{
		return $this->initiatedByCalendar;
	}

	public function shouldPreserveCreationTime(): bool
	{
		return $this->preserveCreationTime;
	}

	public function getPreviousActivity(): ?Activity
	{
		return $this->previousActivity;
	}

	public function getPreviousPayload(): ?ProviderPayload
	{
		return $this->previousPayload;
	}

	public function getPreviousTransit(): ?StorageFieldTransit
	{
		return $this->previousTransit;
	}
}
