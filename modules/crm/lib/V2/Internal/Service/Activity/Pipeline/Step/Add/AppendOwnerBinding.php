<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\SaveBindings;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Result;

/**
 * Puts the owner of the activity into its set of bindings: step 15 of the owner table of an add
 * (crm_activity.php:239-266).
 *
 * AN ACTIVITY IS ALWAYS BOUND TO ITS OWN OWNER. The owner is a column of the activity and a binding is a row
 * of its own, and legacy keeps the two in agreement from this side: whatever the caller named as the owner is
 * appended to the set unless the set already names it. That is what makes an activity created "on a deal"
 * appear in the timeline and in the counters of that deal even when the caller listed no binding at all.
 *
 * IT IS APPENDED AND NOT PREPENDED, AND THAT MATTERS. The rule deriving the owner out of a set reads the ends
 * of it - the last binding that is neither a contact nor a company wins, the first one otherwise - so the place
 * of the appended pair is part of the behaviour rather than a detail. Legacy appends, so this appends.
 *
 * AN OWNER OF NO IDENTIFIER APPENDS NOTHING. Legacy appends the pair whatever it holds, casting a missing owner
 * to a pair of zeroes, and the write of the set drops it again on its own filter (LBS-1 checklist item F1).
 * A binding of V2 cannot be built out of that pair at all, so the case ends here instead of two steps later,
 * with the same stored result. An owner of an undefined type is a different matter and is passed on: the filter
 * of the write is the one place that knows which types the storage accepts ({@see SaveBindings}).
 *
 * THE SET IS THE ONE OF THE MODEL, and the append is a change of the activity rather than a hydration: legacy
 * writes the appended pair into the fields of the caller as well (:270-273, LBS-1 section 11), and the facade
 * reproducing those mutations reads the model.
 *
 * @internal
 */
final class AppendOwnerBinding implements PipelineStepInterface
{
	private const COLUMN_OWNER_ID = 'OWNER_ID';
	private const COLUMN_OWNER_TYPE_ID = 'OWNER_TYPE_ID';

	public function getName(): string
	{
		return 'AppendOwnerBinding';
	}

	/**
	 * Every add asks the question. Whether anything is appended depends on the owner and on the set, and both
	 * answers belong inside: legacy runs the block unconditionally.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$owner = self::ownerOf($context->getTransit());
		if ($owner === null)
		{
			return new Result();
		}

		$bindings = $context->getActivity()->getBindings() ?? [];
		foreach ($bindings as $binding)
		{
			if ($binding->equals($owner))
			{
				return new Result();
			}
		}

		$bindings[] = $owner;
		$context->getActivity()->setBindings(...$bindings);

		return new Result();
	}

	/**
	 * Nothing of legacy stops over an owner it could not bind.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The owner of the activity as a binding, or null where there is no owner to bind. The pair is read from
	 * the transit protectedly: it is the carrier of both columns, and a column it does not carry is the same
	 * "no owner" a legacy array without the key stands for.
	 */
	private static function ownerOf(StorageFieldTransit $transit): ?ActivityBinding
	{
		$ownerId = $transit->has(self::COLUMN_OWNER_ID) ? (int)$transit->get(self::COLUMN_OWNER_ID) : 0;
		if ($ownerId <= 0)
		{
			return null;
		}

		$ownerTypeId = $transit->has(self::COLUMN_OWNER_TYPE_ID)
			? (int)$transit->get(self::COLUMN_OWNER_TYPE_ID)
			: 0
		;

		return new ActivityBinding(OwnerType::fromId($ownerTypeId), $ownerId);
	}
}
