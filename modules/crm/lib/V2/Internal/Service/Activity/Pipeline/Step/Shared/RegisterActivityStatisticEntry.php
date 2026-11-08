<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\Statistics\ActivityStatisticEntry;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityLifecycleService;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmActivityType;

/**
 * Denormalizes the activity itself into the table its statistics are reported out of: step 23 of the owner table
 * of an add (crm_activity.php:324-327) and row 29 of the owner table of an update (:866-869), checklist item
 * C7.6.
 *
 * ONE ROW PER ACTIVITY, PER DAY OF ITS DEADLINE AND PER KIND. Unlike the re-aggregation of a day of a deal, this
 * is a record of this activity alone: the person responsible, the completion, the status, the mark, the stream,
 * the source and the money, upserted under the activity, the day of its deadline and the identity of its
 * provider. The aggregator deletes the row instead of writing it where the values add up to nothing, and it
 * leaves everything alone for a provider that declares no statistics of a communication at all - which is why a
 * to-do item shows nothing but the question being asked (finding of the characterization of `[P1.T4]`).
 *
 * ONE CLASS, TWO OPERATIONS, AND THE DEADLINE IS WHERE THEY DIFFER. Both pipelines ask one question - "is a
 * deadline carried" - and write the same record out of the same shape; what differs is where that deadline
 * lives. An add carries it in the transit of the run, which its check of the fields and its provider filled, and
 * the sentinel of "no deadline" can stand there. An update strips the deadline of the caller before its provider
 * is asked (:2128), so its run carries no such column on nearly every update, while legacy reads the value off
 * the row it re-read - where an activity without a deadline carries the sentinel and the record IS registered
 * under it. The mode is therefore a mode and not an observation over the run: asking the state where the transit
 * happens to be empty would turn every add without a deadline into a registration legacy does not perform.
 *
 * THE GATE IS THE OUTER QUESTION ABOUT THE DEADLINE AND THE SENTINEL PASSES IT. That is not a subtlety to be
 * tidied up: the record of an activity is the one reader of a deadline whose gate is the outer question alone,
 * with no per-binding rule under it. On an add the question is asked of {@see DeadlineSentinelGuard} in its "was
 * anything given" form - and of the run rather than of the column, so that an operation whose deadline was forced
 * to none, an add of an activity of an incoming channel, writes nothing, exactly as legacy has no key at all
 * there ({@see DeadlineSentinelGuard::isGivenToTheRun()}). On an update the column of the state always carries a
 * value, so the gate is open and the value handed over is that column
 * ({@see DeadlineSentinelGuard::columnOf()}).
 *
 * THE COLUMNS OF THE RESULT_* FAMILY COME FROM THE TRANSIT and from nowhere else: the typed model does not hold
 * them, on an add they are put there by {@see MaterializeProviderStatistics} and on an update they arrive with
 * the re-read row.
 *
 * @internal
 */
final class RegisterActivityStatisticEntry implements PipelineStepInterface
{
	/**
	 * The record is keyed by the activity, so there is nothing to record before the row exists. Legacy cannot
	 * reach this state: it interrupts an add on a failed insert, and an update has an activity by definition.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_STATISTIC_ENTRY_WITHOUT_ACTIVITY';

	/** The columns of the record the provider of the activity produces the values of. */
	private const RESULT_COLUMNS = [
		'RESULT_STATUS',
		'RESULT_STREAM',
		'RESULT_MARK',
		'RESULT_SOURCE_ID',
		'RESULT_SUM',
		'RESULT_CURRENCY_ID',
	];

	private const COLUMN_TYPE_ID = 'TYPE_ID';
	private const COLUMN_OWNER_TYPE_ID = 'OWNER_TYPE_ID';

	private const OPERATIONS = [
		ActivityLifecycleService::OPERATION_ADD,
		ActivityLifecycleService::OPERATION_UPDATE,
	];

	/**
	 * @param string $operation which of the two pipelines this instance belongs to, named by whoever declares it.
	 *        The default is the add, so that the declaration written before the mode existed keeps its meaning; an
	 *        update names its own mode and has to, because nothing of the run tells the two apart.
	 * @throws ArgumentException on an operation that records no statistics of an activity.
	 */
	public function __construct(private readonly string $operation = ActivityLifecycleService::OPERATION_ADD)
	{
		if (!in_array($operation, self::OPERATIONS, true))
		{
			throw new ArgumentException(
				"The '{$operation}' operation over an activity records no statistics of its own",
				'operation',
			);
		}
	}

	public function getName(): string
	{
		return 'RegisterActivityStatisticEntry';
	}

	/**
	 * A deadline carried by the operation, the sentinel included - the run of an add, the state of an update. See
	 * the note of the class on why the sentinel counts and on the one shape of it that does not.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if ($this->isUpdate())
		{
			return true;
		}

		return DeadlineSentinelGuard::isGivenToTheRun($context);
	}

	public function execute(PipelineContext $context): Result
	{
		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return (new Result())->addError(new Error('The activity has not been stored.', self::ERROR_NOT_STORED));
		}

		ActivityStatisticEntry::register($activityId, $this->legacyFieldsOf($context));

		return new Result();
	}

	/**
	 * An activity whose record could not be written is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The activity in the legacy form the aggregator reads it in: the day, the owner, the person responsible, the
	 * completion, the identity of the provider and the columns of the provider's own values. The deadline travels
	 * as the text the aggregator parses, and it is the value of the column rather than a real date - the
	 * conversion, and the refusal of a date out of range, belong to the aggregator.
	 *
	 * @return array<string, mixed>
	 */
	private function legacyFieldsOf(PipelineContext $context): array
	{
		$activity = $context->getActivity();
		$transit = $context->getTransit();

		$fields = [
			DeadlineSentinelGuard::COLUMN => (string)$this->deadlineOf($context),
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
			'RESPONSIBLE_ID' => (int)$activity->getResponsibleId(),
			'OWNER_TYPE_ID' => self::intOf($transit, self::COLUMN_OWNER_TYPE_ID, 0),
			'TYPE_ID' => self::intOf($transit, self::COLUMN_TYPE_ID, CCrmActivityType::Provider),
			'PROVIDER_ID' => (string)$activity->getProviderId(),
		];

		// The type of the provider travels only where the activity carries one: the aggregator asks the provider
		// for it, an empty string is an answer the provider gives back unchanged and the aggregator then refuses
		// the whole record over it, while legacy leaves the key out and gets the default of the provider
		// (crm_activity.php:2447-2450, statistics/activitystatisticentry.php:101-104).
		if (($activity->getProviderTypeId() ?? '') !== '')
		{
			$fields['PROVIDER_TYPE_ID'] = (string)$activity->getProviderTypeId();
		}

		foreach (self::RESULT_COLUMNS as $column)
		{
			if ($transit->has($column))
			{
				$fields[$column] = $transit->get($column);
			}
		}

		return $fields;
	}

	/**
	 * The deadline of the operation in the shape its column carries: of the run on an add, of the re-read state on
	 * an update. See the note of the class.
	 */
	private function deadlineOf(PipelineContext $context): mixed
	{
		if ($this->isUpdate())
		{
			return DeadlineSentinelGuard::columnOf($context->getActivity());
		}

		return $context->getTransit()->get(DeadlineSentinelGuard::COLUMN);
	}

	private function isUpdate(): bool
	{
		return $this->operation === ActivityLifecycleService::OPERATION_UPDATE;
	}

	private static function intOf(StorageFieldTransit $transit, string $column, int $default): int
	{
		return $transit->has($column) ? (int)$transit->get($column) : $default;
	}
}
