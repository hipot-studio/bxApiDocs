<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Result;

/**
 * Names the object a new activity belongs to when the caller named only what it is bound to: step 1 of the
 * owner table of an add (`appendOwnerFromBindings`, crm_activity.php:83, :1221-1245), checklist item C1.
 *
 * THE FIRST STEP OF THE PIPELINE, AND IT RUNS BEFORE THE CHECK OF THE FIELDS FOR A REASON. The owner pair is
 * one of the fields that check demands (:1894-1903), so an activity handed over with bindings and no owner
 * would be refused if this ran any later. Everything below reads the pair out of the storage columns of the run
 * as well: the bridge of the live feed, the synchronization of the calendar event, the registration of the
 * statistics of an entity.
 *
 * THE RULE OF THE CHOICE IS NOT THE OBVIOUS ONE. A binding that is neither a contact nor a company wins, and
 * legacy does not stop at the first of them - so it is the LAST such binding that stays; with nothing but
 * clients bound, the first binding of the set is taken. Both halves matter: a deal bound after another deal
 * becomes the owner, and an activity of two contacts belongs to the first of them.
 *
 * A ZERO IS NOT AN OWNER HERE, and that is legacy's own reading (`empty()` of either half, :1224-1225): a pair
 * given as zeroes is derived from the bindings anew, and both halves are then replaced together even when only
 * one of them was empty.
 *
 * WHY THE SAME RULE ALSO LIVES IN THE REPOSITORY. The storage keeps the owner of an activity in step with its
 * bindings whenever the set is written, so
 * {@see \Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository::syncOwnerColumns()} knows how to derive
 * it too. That copy answers for the writers that do not go through a pipeline; this step is the one the run
 * reads, and it exists because the check of the fields above the insert has to see the pair.
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
	 * Only where the caller left the owner out and named something to derive it from.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return !self::hasOwner($context->getTransit()) && self::bindingsOf($context) !== [];
	}

	public function execute(PipelineContext $context): Result
	{
		$owner = self::ownerOf(self::bindingsOf($context));

		$context->getTransit()
			->set(self::COLUMN_OWNER_ID, $owner->getOwnerId())
			->set(self::COLUMN_OWNER_TYPE_ID, $owner->getOwnerType()->getId())
		;

		return new Result();
	}

	/**
	 * Legacy interrupts nothing here: an activity whose owner could not be derived is refused by the check of
	 * the fields below, which is where the pair is demanded.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * @param ActivityBinding[] $bindings a set that is never empty: the predicate of the step saw to that.
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

	/**
	 * @return ActivityBinding[]
	 */
	private static function bindingsOf(PipelineContext $context): array
	{
		return $context->getActivity()->getBindings() ?? [];
	}

	/**
	 * Whether both halves of the pair name something. Legacy asks it with `empty()`, so a zero counts as an
	 * owner nobody named.
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
