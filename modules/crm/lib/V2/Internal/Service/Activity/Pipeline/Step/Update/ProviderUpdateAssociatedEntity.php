<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\AssociatedEntityCapabilityInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Carries the write over to the row of another subsystem the activity is a projection of - a task of the module of
 * tasks, a comment of one, a conference, a generated document: row 38 of the owner table STEP-UPDATE
 * (crm_activity.php:985-993), normative shape TPL-07.
 *
 * THE PROVIDER IS TOLD WHAT THE ACTIVITY NOW IS, AND THE STATE IS THE ONE THE RUN READ BACK. Legacy hands over the
 * row it re-read (:1018) and the providers that react decide by the whole of it, not by the part the operation
 * touched: the provider of tasks refuses an activity whose responsible it cannot see, and the one of conferences
 * needs the row of the conference the column of the re-read state carries. So what travels is the state row 17 left
 * in the run - the typed model and the storage columns beside it - and the set of changes travels along, for the
 * providers that keep both sides in step ({@see AssociatedEntityCapabilityInterface::updateAssociatedEntity()}).
 * Handing over the changes ALONE is what the review of the merge request found: an update of the subject of a task
 * arrived without the responsible that did not change, and the projection was refused before the title reached it.
 *
 * A RUN THAT NEVER READ THE PREVIOUS STATE STILL HAS NOTHING TO SAY HERE, because the set of changes it would hand
 * over says nothing changed, and saying that is a lie the provider acts on.
 *
 * THE HOOK CANNOT REFUSE. Legacy declared it returning a result and dropped that result at the call site, so the
 * activity is written whatever the projection makes of the news; the step never aborts and reports no error of its
 * own past the two preconditions below.
 *
 * WHAT THE CONTEXT IS REALLY FOR (row D9, item 10 of section 7 of LBS-2). The provider of tasks picks who performs
 * the task by a chain of three - the named executor, then the user the caller actually passed, then the person
 * responsible - so the raw user of the caller has to survive the trip
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext::getRawCurrentUser()}). It does: the
 * context of the operation carries the raw value beside the effective one and this step hands the whole context over,
 * exactly as legacy hands over its whole option array.
 *
 * THE PROVIDER IS THE ONE THE RUN RESOLVED. The owner table names the provider of the re-read state, which is what
 * legacy resolves here (:989) off the row it fetched; V2 resolves the provider once, from the state the activity was
 * in (item B8), and every step of the pipeline asks {@see PipelineContext::getProvider()} for it. A second
 * resolution inside this step would be a second answer to one question, and the two can only differ where an update
 * changes the provider of an activity.
 *
 * @internal
 */
final class ProviderUpdateAssociatedEntity implements PipelineStepInterface
{
	/**
	 * The run reached this step without the state the activity was in, so the set of changes it would hand over
	 * says nothing changed. See the note of the class on why that is a refusal rather than an empty call.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	private const COLUMN_ASSOCIATED_ENTITY_ID = 'ASSOCIATED_ENTITY_ID';

	public function getName(): string
	{
		return 'ProviderUpdateAssociatedEntity';
	}

	/**
	 * The gate of legacy, in the order that asks the cheap questions first: the caller did not ask for the
	 * associated entity to be left alone, the provider of the activity has something to say about a projection at
	 * all, and the activity really names one.
	 *
	 * THE CAPABILITY IS THE GATE. Legacy calls the hook for every provider it resolved, because the base class of
	 * its providers defines the method as a no-op; in V2 a provider without the capability has nothing to be called,
	 * and every legacy provider stands behind the adapter, which implements it for all of them.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if ($context->getOperationContext()->shouldSkipAssociatedEntity())
		{
			return false;
		}

		if (!$context->getProvider() instanceof AssociatedEntityCapabilityInterface)
		{
			return false;
		}

		return self::associatedEntityIdOf($context->getTransit()) > 0;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		if (!$context->hasPreviousState())
		{
			return $result->addError(new Error(
				'The previous state of the activity was not loaded.',
				self::ERROR_PREVIOUS_STATE_NOT_LOADED,
			));
		}

		$provider = $context->getProvider();
		if ($provider instanceof AssociatedEntityCapabilityInterface)
		{
			$provider->updateAssociatedEntity(
				self::associatedEntityIdOf($context->getTransit()),
				$context->getActivity(),
				$context->getTransit(),
				$context->getChanges(),
				$context->getOperationContext(),
			);
		}

		return $result;
	}

	/**
	 * An activity whose projection could not be brought into line is written all the same: legacy checks nothing
	 * here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The row of the other subsystem this activity projects, as the column of the re-read state carries it. Row 17
	 * answers that column for every update whose caller said nothing about it.
	 */
	private static function associatedEntityIdOf(StorageFieldTransit $transit): int
	{
		return $transit->has(self::COLUMN_ASSOCIATED_ENTITY_ID)
			? (int)$transit->get(self::COLUMN_ASSOCIATED_ENTITY_ID)
			: 0
		;
	}
}
