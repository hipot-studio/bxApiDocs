<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Activity\CommunicationStatistics;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\MaterializeProviderStatistics;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmActivityType;

/**
 * Hands both states of the activity to the statistics of the communications of the contacts and companies it is or
 * was bound to: row 28 of the owner table STEP-UPDATE (crm_activity.php:863-865), row D6 of the registry of
 * intentional deviations.
 *
 * THE ROW IS UNCONDITIONAL AND EVERY GATE IS INSIDE THE AGGREGATOR, exactly as on an add: it needs a binding to a
 * contact or a company, a deadline and a provider taking part in these statistics at all, and each of those
 * questions is its own. For a provider declaring none - which every to-do item is - the whole observable part of
 * the row is that the provider was asked.
 *
 * THE LOST BINDINGS ARE NOT COMPUTED HERE, and that is the point of handing over two states instead of one. Which
 * owners the activity has left is the aggregator's own rule (`getLostBindings`, a private of it): it takes the
 * contacts and companies of the previous set that the current set no longer names, counts their days off the rows
 * that remain, and only then counts the days of the current set. A second answer to "what is a lost binding" here
 * would be a copy of a rule the aggregator applies anyway, and copies of it would part company over the
 * de-duplication the aggregator performs on both sides first.
 *
 * WHAT EACH SIDE CARRIES. The keys the aggregator reads: the set of bindings, the completion, the deadline and the
 * three columns identifying the provider of the activity - the same shape as on an add, twice. The current side
 * comes from the re-read state and the transit of the run, the previous one from the state read before the write
 * and its own transit. The columns of the RESULT_* family are read by the aggregator off the rows themselves
 * rather than off these arrays, which is why they have to be in the row before this row and are put there by
 * {@see MaterializeProviderStatistics}.
 *
 * THE DEADLINE IS THE ONE THE COLUMN OF EACH STATE CARRIES, THE SENTINEL INCLUDED
 * ({@see DeadlineSentinelGuard::columnOf()}). Legacy feeds this aggregator the two rows it fetched, and a row
 * without a deadline carries the sentinel there: the aggregator then dates its counts by the year 9999 and, for a
 * provider declaring statistics, really does write them. Handing over "no deadline" instead would silently drop
 * that - the aggregator reads an absent deadline as "nothing to count" - and the difference is not the sentinel
 * but the whole registration of every activity of an incoming channel, which is what has no deadline by
 * construction.
 *
 * @internal
 */
final class UpdateCommunicationStatistics implements PipelineStepInterface
{
	/**
	 * The run reached this step without the state the activity was in. The previous side of the comparison is that
	 * state, and an aggregator handed one side alone would count no day the activity has left.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	private const COLUMN_TYPE_ID = 'TYPE_ID';

	public function getName(): string
	{
		return 'UpdateCommunicationStatistics';
	}

	/**
	 * Every update asks the aggregator. See the note of the class on why none of its gates is asked here.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
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

		CommunicationStatistics::updateActivity(
			self::legacyFieldsOf($context->getActivity(), $context->getTransit()),
			self::legacyFieldsOf($context->getPreviousActivity(), $context->getPreviousTransit()),
		);

		return $result;
	}

	/**
	 * An activity whose communications could not be counted is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * One state of the activity in the legacy form the aggregator reads it in: what it names the owners, the day
	 * and the kind of activity by, and nothing besides that.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(Activity $activity, StorageFieldTransit $transit): array
	{
		return [
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
			'TYPE_ID' => $transit->has(self::COLUMN_TYPE_ID)
				? (int)$transit->get(self::COLUMN_TYPE_ID)
				: CCrmActivityType::Provider,
			'PROVIDER_ID' => (string)$activity->getProviderId(),
			'PROVIDER_TYPE_ID' => (string)$activity->getProviderTypeId(),
			DeadlineSentinelGuard::COLUMN => (string)DeadlineSentinelGuard::columnOf($activity),
			'BINDINGS' => self::legacyBindingsOf($activity),
		];
	}

	/**
	 * The set of bindings of the state in the legacy shape. Every owner travels, not the contacts and companies
	 * alone: which of them the statistics of a communication are about is the aggregator's own rule, and a second
	 * copy of it here would be a second answer.
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
