<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderResolver;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Reads the activity being deleted and the provider it belongs to: row 2 of the owner table STEP-DELETE
 * (crm_activity.php:1267-1274), the first of the two steps a delete is interrupted on.
 *
 * EVERYTHING BELOW WORKS ON THE STATE THIS STEP LEAVES BEHIND. A delete has no input representation of its own -
 * the caller names an identifier - so the state of the run is whatever this step read, and every row below it
 * reads that: the recycle bin is handed the fields, the statistics of a deal are computed from the completion and
 * the deadline, the hooks of the provider are given the fields, the record of the timeline reports them. A row
 * that is not there is therefore no operation that partly succeeds, and the step is aborting.
 *
 * THE STATE HANDED OVER BY THE CALLER REPLACES THE READ, AND THAT IS THE ONE WAY AROUND THE CHECK OF THE RIGHT
 * TO READ (item E6.7). Of the three legacy write pipelines only the delete honours a row handed over
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext::getActualItem()}, :1268), and the
 * callers passing one are the mass operations of the portal: emptying an owner, cleaning up volume. Legacy reads
 * with the check of permissions on unless a row was handed over, which is exactly why handing one over bypasses
 * that check - and why a row handed over is accepted even when nothing is stored under that identifier any more.
 * An empty array counts as one handed over, as it does in legacy, which goes on deleting with an empty field
 * array rather than reporting the activity missing.
 *
 * WHAT A LEGACY ROW IS PROJECTED INTO. The three parts of a state, the same ones the storage boundary answers a
 * read with: the columns with a meaning onto the typed model, the serialized ones into the payload, the columns
 * neither of them owns into the transit. The two columns the model holds in a richer form - the deadline and the
 * priority - stay the answer of the model alone, so the transit of a projected row leaves them out exactly as the
 * transit of a read one does. The values are read in the shape legacy carries them in: a completion is the letter
 * Y, a moment is the text of the site.
 *
 * THE PROVIDER OF A DELETE IS RESOLVED HERE, AND ONLY HERE. Four rows below need it - the title the recycle bin
 * names the activity by, the entity a provider associated with it, its calendar event, the hook after the
 * deletion - and the row of the recycle bin is conditional, so resolving it there would leave a delete that never
 * reached the bin without a provider at all. It is resolved from the state that has just been read, which is what
 * legacy resolves it from (:1303, :1462), and the steps below read it off the run instead of resolving again.
 *
 * @internal
 */
final class LoadActivity implements PipelineStepInterface
{
	/**
	 * There is no activity with that id, and the caller handed no state over. The message is the comment legacy
	 * answers its false with.
	 */
	public const ERROR_NOT_FOUND = 'CRM_ACTIVITY_NOT_FOUND';

	/**
	 * The run reached this step with no activity to delete, which the entry point of the service refuses to build.
	 * It is a mistake of whoever assembled the run rather than of the caller.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	private readonly ActivityRepositoryInterface $repository;

	private readonly ProviderResolver $resolver;

	/**
	 * @param ActivityRepositoryInterface|null $repository the storage boundary of the activities.
	 * @param ProviderResolver|null $resolver what answers which provider owns the activity that was read.
	 */
	public function __construct(
		?ActivityRepositoryInterface $repository = null,
		?ProviderResolver $resolver = null,
	)
	{
		$this->repository = $repository ?? new ActivityRepository();
		$this->resolver = $resolver ?? new ProviderResolver();
	}

	public function getName(): string
	{
		return 'LoadActivity';
	}

	/**
	 * Every delete works on a state. Legacy has no path around this.
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

		$givenRow = $context->getOperationContext()->getActualItem();
		$state = $givenRow === null
			? $this->repository->getStateById($activityId)
			: self::stateOfTheGivenRow($givenRow)
		;

		if ($state === null)
		{
			return $result->addError(new Error(
				"Could not find CrmActivity(ID = {$activityId})",
				self::ERROR_NOT_FOUND,
			));
		}

		$activity = $state[ActivityRepositoryInterface::STATE_ACTIVITY];
		$activity->internalSet(Activity::id, $activityId);
		$context->setActivity($activity);

		self::fillInTheTransit($context->getTransit(), $state[ActivityRepositoryInterface::STATE_TRANSIT]);
		self::fillInThePayload($context->getPayload(), $state[ActivityRepositoryInterface::STATE_PAYLOAD]);

		$context->setProvider($this->resolver->resolve(
			$activity->getProviderId(),
			self::typeOfActivity($context->getTransit()),
		));

		return $result;
	}

	/**
	 * A delete of an activity that is not there performs none of its effects.
	 */
	public function isAbortingOnFailure(): bool
	{
		return true;
	}

	/**
	 * The state of the run keeps what some step of it has already put into a carrier, and takes the rest from the
	 * state that was read: a delete assembled by hand may name a column, and the row answers everything it did not.
	 */
	private static function fillInTheTransit(StorageFieldTransit $ofTheRun, StorageFieldTransit $ofTheState): void
	{
		foreach ($ofTheState->getValues() as $column => $value)
		{
			if (!$ofTheRun->has($column))
			{
				$ofTheRun->set($column, $value);
			}
		}
	}

	private static function fillInThePayload(ProviderPayload $ofTheRun, ProviderPayload $ofTheState): void
	{
		foreach ($ofTheState->getValues() as $field => $value)
		{
			if (!$ofTheRun->has($field))
			{
				$ofTheRun->set($field, $value);
			}
		}
	}

	/**
	 * The legacy row of the caller as the three parts of a state - see the note of the class.
	 *
	 * @param array<string, mixed> $row
	 * @return array{activity: Activity, payload: ProviderPayload, transit: StorageFieldTransit}
	 */
	private static function stateOfTheGivenRow(array $row): array
	{
		$activity = new Activity();
		$payload = new ProviderPayload();
		$transit = new StorageFieldTransit();

		foreach (ActivityFieldMap::getCoreColumns() as $column)
		{
			if (array_key_exists($column, $row))
			{
				$activity->internalSet(
					(string)ActivityFieldMap::getFieldName($column),
					self::fieldValueOfALegacyColumn($column, $row[$column]),
				);
			}
		}

		foreach (ProviderPayload::getSupportedFields() as $field)
		{
			$column = ActivityFieldMap::getPayloadColumn($field);
			if (array_key_exists($column, $row))
			{
				$payload->set($field, $row[$column]);
			}
		}

		foreach (StorageFieldTransit::getSupportedFields() as $column)
		{
			if (ActivityFieldMap::getFieldName($column) === null && array_key_exists($column, $row))
			{
				$transit->set($column, $row[$column]);
			}
		}

		return [
			ActivityRepositoryInterface::STATE_ACTIVITY => $activity,
			ActivityRepositoryInterface::STATE_PAYLOAD => $payload,
			ActivityRepositoryInterface::STATE_TRANSIT => $transit,
		];
	}

	/**
	 * The value of a column as the model holds it, read out of the shape a legacy field array carries: the letter
	 * of a completion, the text of a moment. Everything else is the correspondence the field map already owns.
	 */
	private static function fieldValueOfALegacyColumn(string $column, mixed $value): mixed
	{
		if ($column === 'COMPLETED')
		{
			return $value === 'Y';
		}

		return ActivityFieldMap::toFieldValue(
			$column,
			ActivityFieldMap::getType($column) === ActivityFieldMap::TYPE_DATETIME
				? self::momentOf($value)
				: $value
			,
		);
	}

	private static function momentOf(mixed $value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		return is_string($value) && $value !== '' ? DateTime::tryParse($value) : null;
	}

	private static function typeOfActivity(StorageFieldTransit $transit): ?int
	{
		return $transit->has('TYPE_ID') ? (int)$transit->get('TYPE_ID') : null;
	}
}
