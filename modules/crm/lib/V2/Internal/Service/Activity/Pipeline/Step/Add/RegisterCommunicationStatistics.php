<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Activity\CommunicationStatistics;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\MaterializeProviderStatistics;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Result;
use CCrmActivityType;

/**
 * Hands the activity to the statistics of the communications of the contacts and companies it is bound to:
 * step 22 of the owner table of an add (crm_activity.php:323), checklist item C7.5.
 *
 * THE STEP IS UNCONDITIONAL AND EVERY GATE IS INSIDE THE AGGREGATOR. Legacy calls it on every add, and the
 * aggregator decides for itself whether there is anything to do: it needs a binding to a contact or a company,
 * a deadline, and a provider that takes part in the statistics of a communication at all. So for a provider
 * activity whose provider declares no such statistics - which is what a to-do item is - the only observable
 * part of the step is that the provider is asked (finding of the characterization of `[P1.T4]`).
 *
 * WHAT THE AGGREGATOR COUNTS. Per contact and per company, per day of the deadline and per kind of activity: how
 * many there were, and, where the provider declares them, the statuses, the streams, the marks per source of the
 * communication and the money. The values themselves are read out of the rows of `b_crm_act`, not out of the
 * array handed over - the array says which day, which owners and which kind of activity to re-read - which is
 * why the columns of the RESULT_* family have to be in the row before this step and are put there by
 * {@see MaterializeProviderStatistics}.
 *
 * WHAT THE ARRAY CARRIES. The set of bindings the write of the bindings left in the model, the completion, the
 * deadline and the three columns the provider of the activity is identified by. The deadline is read protectedly
 * and the sentinel of "no deadline" is left out: legacy reaches this step with the key absent for an activity
 * that has none, and the aggregator reads an absent deadline as "nothing to register" while it would take the
 * sentinel for a date of the year 9999.
 *
 * @internal
 */
final class RegisterCommunicationStatistics implements PipelineStepInterface
{
	private const COLUMN_TYPE_ID = 'TYPE_ID';

	public function getName(): string
	{
		return 'RegisterCommunicationStatistics';
	}

	/**
	 * Every add asks the aggregator. See the note of the class on why none of its gates is asked here.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		CommunicationStatistics::registerActivity(self::legacyFieldsOf($context));

		return new Result();
	}

	/**
	 * An activity whose communications could not be counted is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The activity in the legacy form the aggregator reads it in: what it names the owners, the day and the kind
	 * of activity by, and nothing besides that.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(PipelineContext $context): array
	{
		$activity = $context->getActivity();
		$transit = $context->getTransit();

		$fields = [
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
			'TYPE_ID' => $transit->has(self::COLUMN_TYPE_ID)
				? (int)$transit->get(self::COLUMN_TYPE_ID)
				: CCrmActivityType::Provider,
			'PROVIDER_ID' => (string)$activity->getProviderId(),
			'BINDINGS' => self::legacyBindingsOf($activity),
		];

		// The type of the provider travels only where the activity carries one: an empty string is a value of the
		// column and the providers reading it through `??` would take it for one, while legacy leaves the key out
		// altogether and lets `getTypeId()` answer with its own default (crm_activity.php:2447-2450).
		if (($activity->getProviderTypeId() ?? '') !== '')
		{
			$fields['PROVIDER_TYPE_ID'] = (string)$activity->getProviderTypeId();
		}

		$deadline = DeadlineSentinelGuard::readFrom($transit);
		if ($deadline !== null)
		{
			$fields[DeadlineSentinelGuard::COLUMN] = (string)$deadline;
		}

		return $fields;
	}

	/**
	 * The effective set of bindings in the legacy shape. Every owner travels, not the contacts and companies
	 * alone: which of them the statistics of a communication are about is the aggregator's own rule
	 * ({@see OwnerType::CONTACT}, {@see OwnerType::COMPANY}), and a second copy of it here would be a second
	 * answer.
	 *
	 * @return array<int, array<string, int>>
	 */
	private static function legacyBindingsOf(Activity $activity): array
	{
		$bindings = [];
		foreach ($activity->getBindings() ?? [] as $binding)
		{
			$bindings[] = [
				'OWNER_TYPE_ID' => $binding->getOwnerType()->getId(),
				'OWNER_ID' => $binding->getOwnerId(),
			];
		}

		return $bindings;
	}
}
