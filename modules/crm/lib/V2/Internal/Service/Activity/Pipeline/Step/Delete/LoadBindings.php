<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityBindingRepository;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Answers what the activity being deleted is bound to: row 6 of the owner table STEP-DELETE
 * (crm_activity.php:1345-1347).
 *
 * FOUR ROWS BELOW READ THIS ANSWER AND NONE OF THEM ASKS AGAIN. The timeline of row 7, the read model of the
 * uncompleted activities of row 10, the history of row 11 and the statistics of row 12 all work per binding, and the
 * set they work over has to be the one the activity had before row 8 erased its rows. Legacy reads it into a local
 * for exactly that reason and every one of those effects reads that local.
 *
 * THE SET HANDED OVER BY THE CALLER REPLACES THE READ (item E6.8), and it is a different key of the operation from
 * the state of row 2: a caller may hand over one, the other, both or neither. The mass operations of the portal that
 * empty an owner already know what they are unbinding, and legacy takes their word for it without a query. An empty
 * set counts as one handed over, as it does in legacy, whose only other outcome is the false of a non-positive
 * identifier.
 *
 * THE SET IS A FIELD OF THE MODEL, so it goes into the model of the run through the hydration path - the value read
 * from storage that it is. It is put there even when it is empty: "nobody has answered yet" has to stay a different
 * answer from "the activity is bound to nothing", and that difference is the condition of rows 7, 10 and 17, which
 * legacy states as `is_array($arBindings)`.
 *
 * A SET HANDED OVER IS REDUCED BY THE RULES THE READ IS REDUCED BY. The storage boundary answers a read with the
 * bindings its rows describe - an owner of a non-positive id or type describes none - and collapses the rows of one
 * owner into one binding; a set handed over is projected by the same rules, so that "the bindings of the activity"
 * means one thing whichever way they arrived. Legacy carries the raw rows on instead and lets each consumer drop what
 * it cannot read ({@see \Bitrix\Crm\ItemIdentifier::createFromArray()} answering null, the read model filtering its
 * own): the observable difference is that a caller repeating an owner has the effects performed once here and twice
 * there.
 *
 * @internal
 */
final class LoadBindings implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. A binding
	 * belongs to a stored activity, so there is nothing to read and nothing to answer.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	private const COLUMN_OWNER_TYPE_ID = 'OWNER_TYPE_ID';

	private const COLUMN_OWNER_ID = 'OWNER_ID';

	private readonly ActivityBindingRepository $repository;

	/**
	 * @param ActivityBindingRepository|null $repository the storage boundary of the bindings; the one seam of the
	 *        step.
	 */
	public function __construct(?ActivityBindingRepository $repository = null)
	{
		$this->repository = $repository ?? new ActivityBindingRepository();
	}

	public function getName(): string
	{
		return 'LoadBindings';
	}

	/**
	 * Every delete answers what its activity was bound to. Legacy has no path around this, and the rows reading the
	 * answer carry their own condition.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		$given = $context->getOperationContext()->getActualBindings();
		$context->getActivity()->internalSet(
			Activity::bindings,
			$given === null ? $this->repository->getByActivityId($activityId) : self::bindingsOfTheGivenSet($given),
		);

		return $result;
	}

	/**
	 * Legacy interrupts a delete on the state it loads, on the restriction of the calls and on the deletion of the
	 * row - and on nothing else.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The set the caller handed over as the bindings of the activity - see the note of the class on the rules it is
	 * reduced by.
	 *
	 * @param array<int, array<string, mixed>> $given
	 * @return ActivityBinding[]
	 */
	private static function bindingsOfTheGivenSet(array $given): array
	{
		$bindings = [];
		foreach ($given as $row)
		{
			$ownerId = (int)($row[self::COLUMN_OWNER_ID] ?? 0);
			$ownerTypeId = (int)($row[self::COLUMN_OWNER_TYPE_ID] ?? 0);
			if ($ownerId <= 0 || $ownerTypeId <= 0)
			{
				continue;
			}

			$binding = new ActivityBinding(OwnerType::fromId($ownerTypeId), $ownerId);
			$bindings[$binding->getKey()] ??= $binding;
		}

		return array_values($bindings);
	}
}
