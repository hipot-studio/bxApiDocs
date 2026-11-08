<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\Activity\CommunicationStatistics;
use Bitrix\Crm\Activity\StatisticsMark;
use Bitrix\Crm\Activity\StatisticsStatus;
use Bitrix\Crm\Activity\StatisticsStream;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityLifecycleService;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\LegacyProviderAdapter;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;

/**
 * The one point of a pipeline where the statistical data of a provider becomes the columns of the RESULT_*
 * family (canonical block ALG-05, decision 10 of the field audit): step 18 of the owner table of an add and
 * step 26 of the owner table of an update, in both cases immediately above the steps registering the
 * statistics.
 *
 * WHY THE COLUMNS EXIST AT ALL IN V2. Nothing of the family reaches the typed model: the meaning of a status,
 * a stream, a mark, a source and a sum belongs to the provider that produced them - telephony, the open lines,
 * an external channel, a form of the CRM - and the audit keeps them out of the core on purpose. The readers,
 * however, are aggregators over the table: the statistics of the communications re-aggregate a day straight
 * out of `b_crm_act`, and the statistic entry of an activity denormalizes the same values into `b_crm_act_stat`.
 * So the columns have to be filled whenever such data is written, and this is the single place that guarantees
 * it - "single" being the whole point: two places filling them would be two answers about one row.
 *
 * WHAT IT ASKS AND OF WHOM. The provider of the activity declares which kinds of statistics of a communication
 * it takes part in, and the declaration is what tells "there is data here" from "this activity has no
 * statistics at all" ({@see self::COLUMNS_BY_STATISTICS}). The declaration has no shape in the contract of a V2
 * provider, exactly as the refusal of the activity stream has none, so it is asked of the legacy class behind
 * the adapter and of nothing else: a provider that is not there, or one without a legacy class behind it,
 * declares nothing and this step does not run. When the capability appears in the contract of a provider, this
 * is the one method to widen - the columns, the defaults and the readers do not change.
 *
 * A KIND WITHOUT A COLUMN IS NOT A REASON TO RUN. The quantity of the communications is counted off the rows
 * themselves, so a provider declaring nothing but the quantity - an email, a meeting, a message of a messenger,
 * a record of the activity stream - has no column of this family to fill and the step is not applicable to it.
 *
 * ON AN ADD THE DECLARED COLUMNS ARE COMPLETED, ON AN UPDATE THEY ARE NOT. The values the aggregators read for
 * an activity that has just been created are the values of its input, and legacy reads them off the very same
 * array with `isset() ? ... : 0` - so the defaults written here are the values legacy derives, and the row
 * receives what it received before. On an update the state has been re-read, every column of the row is
 * already carried, and writing a default over it would erase a value nobody asked to change: an update that
 * declares nothing about the statistics leaves them exactly as they are.
 *
 * NOTHING IS WRITTEN TO THE DATABASE HERE, and that is a property of the position rather than an omission. The
 * row of the activity is written before this step in both pipelines - inserted on an add, updated and re-read
 * on an update - out of the same transit this step materializes into, so the stored row already holds every
 * declared value and a write of its own would be a second UPDATE per created call. A future provider that
 * declares its statistics only after the row exists needs a write, and the owner of that provider adds it - or
 * moves this step above the insert.
 *
 * WHAT IS NOT REPAIRED (parity, and none of the three has a row in the registry of deviations): a neutral mark
 * is written as it is and collapsed into "none" by the aggregator, the default of a numeric column is
 * indistinguishable from an explicit "undefined", and the source of a communication is stored empty while the
 * aggregator reads an empty one as its own default - so the literal of that default never reaches a column.
 *
 * RESULT_VALUE IS NOT MATERIALIZED, AND THAT IS NOT THE SAME AS BEING LOST. It is no statistical datum a provider
 * derives - it is a value of the caller, and the one caller writing it is the REST method of an external channel,
 * which demands it of its own caller (`lib/rest/externalchannel.php:87`, error 7003) and then writes it through
 * `CCrmActivity::Add/Update`. Legacy has no writer of its own for the column and no default for it either, so
 * neither has this step: it belongs to no line of ALG-05, and writing a default over it on an add would invent a
 * value legacy never wrote. What it does need is a carrier, and it has one - the transit of the storage columns -
 * so the value of the caller reaches the row and an operation that does not name it leaves the stored one alone.
 *
 * ITS READERS EXIST, so the earlier claim of "no reader whatsoever" was wrong: the statistics of the
 * communications re-aggregate it out of `b_crm_act` (`lib/activity/communicationstatistics.php:404, :511`) and the
 * snapshot of the recycle bin carries it (`lib/recycling/activitycontroller.php:54`), which is what makes a
 * restoration able to rebuild the column at all.
 *
 * @internal
 */
final class MaterializeProviderStatistics implements PipelineStepInterface
{
	/**
	 * The columns each kind of statistics of a communication is read out of. NORMATIVE and closed: this is the
	 * whole of what the point materializes, and the six columns are the six lines of ALG-05.
	 *
	 * The quantity of the communications is absent on purpose - it is counted off the rows. The mark brings the
	 * source with it because the aggregator counts the marks per source of a communication.
	 */
	public const COLUMNS_BY_STATISTICS = [
		CommunicationStatistics::STATISTICS_STATUSES => ['RESULT_STATUS'],
		CommunicationStatistics::STATISTICS_STREAMS => ['RESULT_STREAM'],
		CommunicationStatistics::STATISTICS_MARKS => ['RESULT_MARK', 'RESULT_SOURCE_ID'],
		CommunicationStatistics::STATISTICS_MONEY => ['RESULT_SUM', 'RESULT_CURRENCY_ID'],
	];

	/**
	 * The value of a declared column the operation says nothing about, written on an add alone. Every one of
	 * them is the value the readers of legacy derive from an absent key of the field array.
	 */
	private const DEFAULTS = [
		'RESULT_STATUS' => StatisticsStatus::Undefined,
		'RESULT_STREAM' => StatisticsStream::Undefined,
		'RESULT_MARK' => StatisticsMark::None,
		'RESULT_SOURCE_ID' => '',
		'RESULT_SUM' => 0.0,
		'RESULT_CURRENCY_ID' => '',
	];

	private const OPERATIONS = [
		ActivityLifecycleService::OPERATION_ADD,
		ActivityLifecycleService::OPERATION_UPDATE,
	];

	/**
	 * @param string $operation which of the two pipelines this instance belongs to, named by whoever declares
	 *        it. The mode is not derived from the state of the run: a set of changes that happens to be empty
	 *        does not turn an update into an add.
	 * @throws ArgumentException on an operation that writes no statistical data of a provider.
	 */
	public function __construct(private readonly string $operation)
	{
		if (!in_array($operation, self::OPERATIONS, true))
		{
			throw new ArgumentException(
				"The '{$operation}' operation over an activity materializes no statistics of a provider",
				'operation',
			);
		}
	}

	public function getName(): string
	{
		return 'MaterializeProviderStatistics';
	}

	/**
	 * Whether the provider of the activity declares statistics of a communication that have columns of their
	 * own. A provider that declares none, or declares only the kinds counted off the rows, leaves the columns
	 * untouched - and so does an activity with no provider at all.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return self::declaredColumnsOf($context) !== [];
	}

	public function execute(PipelineContext $context): Result
	{
		$transit = $context->getTransit();
		foreach (self::declaredColumnsOf($context) as $column)
		{
			if ($transit->has($column))
			{
				$transit->set($column, self::normalized($column, $transit->get($column)));

				continue;
			}

			if ($this->operation === ActivityLifecycleService::OPERATION_ADD)
			{
				$transit->set($column, self::DEFAULTS[$column]);
			}
		}

		return new Result();
	}

	/**
	 * Legacy interrupts nothing over the statistics of a communication, and neither does this step: an activity
	 * is stored whether its aggregators get their columns or not.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The columns of the family the provider of this activity declares data for, in the order of the audit.
	 *
	 * @return string[]
	 */
	private static function declaredColumnsOf(PipelineContext $context): array
	{
		$columns = [];
		foreach (self::declaredStatisticsOf($context) as $statistics)
		{
			foreach (self::COLUMNS_BY_STATISTICS[$statistics] ?? [] as $column)
			{
				$columns[] = $column;
			}
		}

		return $columns;
	}

	/**
	 * The kinds of statistics of a communication the provider takes part in.
	 *
	 * @return string[]
	 */
	private static function declaredStatisticsOf(PipelineContext $context): array
	{
		$provider = $context->getProvider();
		if (!$provider instanceof LegacyProviderAdapter)
		{
			return [];
		}

		$legacyClassName = $provider->getLegacyClassName();

		return $legacyClassName::getSupportedCommunicationStatistics();
	}

	/**
	 * The value in the type its column is declared with, so that an aggregator reading the row and a step
	 * reading the transit see one and the same number. The type has one definition and it belongs to the map of
	 * the columns.
	 */
	private static function normalized(string $column, mixed $value): mixed
	{
		return match (ActivityFieldMap::getType($column))
		{
			ActivityFieldMap::TYPE_INT => (int)$value,
			ActivityFieldMap::TYPE_FLOAT => (float)$value,
			default => (string)$value,
		};
	}
}
