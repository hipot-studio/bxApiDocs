<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Result;

/**
 * Keeps the object an activity belongs to in step with the set it is being bound to: row 6 of the owner table of
 * an update (crm_activity.php:559-580).
 *
 * THREE ANSWERS WHERE AN ADD HAS ONE, and that is why this is a class of its own rather than the step of the same
 * name above the insert. An add only ever appends an owner it could not find; an update also takes one away.
 * A set of exactly one binding names the owner outright - whatever the operation said about the pair, and that is
 * the whole point: moving an activity onto one deal moves its owner with it. A set that is empty clears the owner
 * to nobody. Only with more than one binding does the rule of an add apply (:579, `appendOwnerFromBindings`
 * :1222-1246): the pair is filled in where it is empty and left exactly as it is where the operation named it.
 *
 * THE SET IS THE ONE THIS OPERATION NAMED, AND NOT THE ONE THE ACTIVITY HAS. Legacy asks whether its field array
 * carries a set of bindings at all (:560), so an update that says nothing about them keeps the bindings of the
 * activity and its owner along with them - no branch of this step runs. The question is asked of the model of the
 * run, in the form the rest of the pipeline asks it in, and the prev-set of the snapshot is no answer to it.
 *
 * THE RULE OF THE CHOICE IS SPELLED OUT A THIRD TIME, on purpose. It also lives in the step of an add and in
 * {@see \Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository::syncOwnerColumns()}, and none of the three
 * may delegate to another: the repository answers for the writers that go through no pipeline, the step of an add
 * runs above the check of the fields that demands the pair, and this one is a different rule altogether - the two
 * branches above it exist nowhere else. Sharing one instance between two pipelines is what the design forbids
 * outright, because the two steps would then be one row of two owner tables.
 *
 * @internal
 */
final class ResolveOwnerFromBindings implements PipelineStepInterface
{
	private const COLUMN_OWNER_ID = 'OWNER_ID';
	private const COLUMN_OWNER_TYPE_ID = 'OWNER_TYPE_ID';

	public function getName(): string
	{
		return 'ResolveOwnerFromBindings';
	}

	/**
	 * Only where the operation named a set of bindings. Naming an empty one is naming a set.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return $context->getActivity()->isFieldChanged(Activity::bindings);
	}

	public function execute(PipelineContext $context): Result
	{
		$bindings = $context->getActivity()->getBindings() ?? [];
		$transit = $context->getTransit();

		if ($bindings === [])
		{
			$transit
				->set(self::COLUMN_OWNER_ID, 0)
				->set(self::COLUMN_OWNER_TYPE_ID, OwnerType::UNDEFINED)
			;

			return new Result();
		}

		if (count($bindings) === 1)
		{
			self::writeOwner($transit, reset($bindings));

			return new Result();
		}

		if (!self::hasOwner($transit))
		{
			self::writeOwner($transit, self::ownerOf($bindings));
		}

		return new Result();
	}

	/**
	 * Legacy interrupts nothing over the owner of an activity, and an update refuses nothing about it either: the
	 * check of the fields of an update demands no owner at all.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The binding that becomes the owner where more than one is bound and the operation named no owner. A binding
	 * that is neither a contact nor a company wins, and legacy does not stop at the first of them - so it is the
	 * LAST such binding that stays; with nothing but clients bound, the first binding of the set is taken.
	 *
	 * @param ActivityBinding[] $bindings a set of at least two: the branches above saw to that.
	 */
	private static function ownerOf(array $bindings): ActivityBinding
	{
		$owner = null;
		foreach ($bindings as $binding)
		{
			$ownerTypeId = $binding->getOwnerType()->getId();
			if ($ownerTypeId !== OwnerType::CONTACT && $ownerTypeId !== OwnerType::COMPANY)
			{
				$owner = $binding;
			}
		}

		return $owner ?? reset($bindings);
	}

	private static function writeOwner(StorageFieldTransit $transit, ActivityBinding $owner): void
	{
		$transit
			->set(self::COLUMN_OWNER_ID, $owner->getOwnerId())
			->set(self::COLUMN_OWNER_TYPE_ID, $owner->getOwnerType()->getId())
		;
	}

	/**
	 * Whether both halves of the pair name something. Legacy asks it with `empty()`, so a zero counts as an owner
	 * nobody named - which is exactly the pair the branch of the empty set leaves behind.
	 */
	private static function hasOwner(StorageFieldTransit $transit): bool
	{
		return self::isFilled($transit, self::COLUMN_OWNER_ID)
			&& self::isFilled($transit, self::COLUMN_OWNER_TYPE_ID)
		;
	}

	private static function isFilled(StorageFieldTransit $transit, string $column): bool
	{
		return $transit->has($column) && (int)$transit->get($column) > 0;
	}
}
