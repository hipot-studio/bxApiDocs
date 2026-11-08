<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\Activity\IncomingChannel;
use Bitrix\Crm\Activity\Provider\Base as LegacyProviderBase;
use Bitrix\Crm\Activity\Provider\Eventable\PingOffset;
use Bitrix\Crm\Activity\Provider\Eventable\PingQueue;
use Bitrix\Crm\Recycling\ActivityController;
use Bitrix\Crm\Settings\ActivitySettings;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityStorageElementRepository;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderRegistry;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderRegistryInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Moves the activity to the recycle bin instead of erasing it: row 5 of the owner table STEP-DELETE
 * (crm_activity.php:1295-1338).
 *
 * THE EARLY EXIT OF THIS ROW IS AN INVARIANT OF THE DESIGN (item E3). Where the provider of the activity reports
 * the transfer as a deletion of its own - which the two providers of tasks do, letting the module of tasks remove
 * the projection itself - legacy returns a plain true and performs NONE of the seventeen rows below: the row of the
 * activity stays, its bindings and communications stay, the timeline is not told, the counters are not recomputed
 * and the event of a deleted activity is never published, so no subscriber learns about a deletion that can still
 * be undone. The pipeline says that with a success that stops the run
 * ({@see PipelineStepInterface::STOP_WITH_SUCCESS}), which is what that outcome of the engine exists for. The three
 * registrations legacy does unregister on that path - the incoming channel and both halves of the reminders - are
 * performed here, because row 9 is one of the rows that never runs.
 *
 * THE CONDITION IS REPRODUCED TO THE LETTER, AND ONLY ONE OF ITS THREE PARTS IS ALWAYS TRUE. The setting of the
 * module answers true unconditionally (row RECYCLE-ALWAYS-ON of the registry of deviations) - and that is the whole
 * of what the row says. The module of the recycle bin still has to be installed, which is a portal without it saying
 * no, and the transfer below still has to report a success. So the rows reading "the activity never reached the bin"
 * - the files of row 8 and the activity stream of row 19 - are reachable, and they are declared rather than forced
 * away. The demand of the caller is asked first of all, and an activity the caller says is already in the bin is not
 * put there twice - that is the state a delete of an activity being erased from the bin arrives in.
 *
 * THE TITLE OF THE BINNED ACTIVITY IS THE ANSWER OF ITS PROVIDER, AND IT OUTLIVES THIS ROW. Legacy overwrites the
 * subject of its field array with the title the provider builds, and that array travels on to every row below - the
 * timeline, the statistics of the communications, the hooks of the provider, the search index all see the title and
 * not the stored subject. So the title is written onto the model of the run rather than into the array handed to the
 * bin alone. Asking for it takes the legacy class of the provider: the contract of the provider layer has no
 * capability for a title, so what the registry names as the class of the resolved provider is asked, and a provider
 * whose class is no legacy one leaves the subject alone.
 *
 * WHAT THE BIN IS HANDED IS THE WHOLE ROW, BECAUSE THE SLOT IT STORES IS BUILT OUT OF IT. The controller keeps
 * exactly the fields it was given ({@see ActivityController::prepareEntityData()} intersects them with the declared
 * slot), and a restoration can rebuild only what the slot carries - so every column of the state travels, including
 * the one the state has no carrier for: the identifiers of the files, which live in a table of their own with a
 * writer of their own and are asked of that writer here.
 *
 * TWO FACTS OF THE TRANSFER OUTLIVE THIS ROW AND ONLY THIS ROW CAN REPORT THEM: that the activity went to the bin
 * ({@see PipelineContext::RUN_VALUE_MOVED_TO_RECYCLE_BIN}) and which row of the bin it became
 * ({@see PipelineContext::RUN_VALUE_RECYCLING_ENTITY_ID}). Row 16 reads both, and by then the row of the activity is
 * erased, so no storage the rows below can reach still tells them apart. The second of the two is left behind even
 * where the transfer FAILED - the value being whatever the failed result carries, that is nothing - because legacy
 * asks the bin itself only where this row never ran (crm_activity.php:1481-1488), and a failed transfer is not that.
 *
 * @internal
 */
final class MoveToRecycleBin implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/** The key the transfer reports "the provider has deleted the activity itself" under. */
	private const DATA_IS_DELETED = 'isDeleted';

	/** The key the transfer names the row of the bin it created under. */
	private const DATA_RECYCLING_ENTITY_ID = 'recyclingEntityId';

	private const COLUMN_SUBJECT = 'SUBJECT';
	private const COLUMN_IS_INCOMING_CHANNEL = 'IS_INCOMING_CHANNEL';
	private const COLUMN_STORAGE_ELEMENT_IDS = 'STORAGE_ELEMENT_IDS';

	/**
	 * The one column of the row the bin never carries: a search index the storage derives, which is why the
	 * declared slot of the controller has no name for it.
	 */
	private const COLUMN_OUTSIDE_THE_SLOT = 'SEARCH_CONTENT';

	/**
	 * The one declared name of the slot a real transfer never fills. Legacy builds the fields out of a row read
	 * with `GetList` and `*`, and that reader has no entry for this column at all (section 12 of FIELD-AUDIT: it
	 * exists in the schema and in no field map), so the intersection of the controller silently drops it. Nothing
	 * is lost by it - a restoration goes through `CCrmActivity::Add()`, which derives the flag from the completion
	 * again - but the slot of V2 has to be as narrow as the slot of legacy, or a restoration would reproduce a
	 * stored value legacy loses.
	 */
	private const COLUMN_THE_READER_OF_LEGACY_CANNOT_SELECT = 'IS_HANDLEABLE';

	private const OPTION_FIELDS = 'FIELDS';
	private const OPTION_FORCE_USER_ID = 'FORCE_USER_ID';

	private readonly ActivityStorageElementRepository $storageElements;

	private readonly ?ProviderRegistryInterface $registry;

	/**
	 * @param ActivityStorageElementRepository|null $storageElements the writer of the files of an activity, asked
	 *        for the identifiers the slot of the bin has to carry.
	 * @param ProviderRegistryInterface|null $registry what names the legacy class of the provider of the activity;
	 *        the registry of the service locator when the caller names none.
	 */
	public function __construct(
		?ActivityStorageElementRepository $storageElements = null,
		?ProviderRegistryInterface $registry = null,
	)
	{
		$this->storageElements = $storageElements ?? new ActivityStorageElementRepository();
		$this->registry = $registry;
	}

	public function getName(): string
	{
		return 'MoveToRecycleBin';
	}

	/**
	 * The activity is not put into the bin twice, the module of the bin has to be there, and either the caller or
	 * the setting of the module has to ask for it - see the note of the class.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		$operationContext = $context->getOperationContext();

		return !$operationContext->isMovedToRecycleBin()
			&& ActivityController::isEnabled()
			&& (
				$operationContext->shouldEnableRecycleBin() === true
				|| ActivitySettings::getCurrent()->isRecycleBinEnabled()
			)
		;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		$fields = $this->legacyFieldsOf($context);
		$this->retitle($context, $fields);

		$transferResult = ActivityController::getInstance()
			->moveToBin($activityId, $this->optionsOf($context, $fields))
		;
		$context->setRunValue(
			PipelineContext::RUN_VALUE_RECYCLING_ENTITY_ID,
			self::recyclingEntityIdOf($transferResult),
		);

		if (!$transferResult->isSuccess())
		{
			return $result;
		}

		$context->setRunValue(PipelineContext::RUN_VALUE_MOVED_TO_RECYCLE_BIN, true);

		if (!self::reportsTheActivityDeleted($transferResult))
		{
			return $result;
		}

		IncomingChannel::getInstance()->unregister($activityId);
		PingOffset::getInstance()->unregister($activityId);
		PingQueue::getInstance()->unregister($activityId);

		return $result->setData([PipelineStepInterface::STOP_WITH_SUCCESS => true]);
	}

	/**
	 * A transfer that failed is not an interruption: legacy carries on erasing the row, and so does the run.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function optionsOf(PipelineContext $context, array $fields): array
	{
		$options = [self::OPTION_FIELDS => $fields];

		$forceUserId = $context->getOperationContext()->getRecycleBinForceUserId();
		if ($forceUserId !== null)
		{
			$options[self::OPTION_FORCE_USER_ID] = $forceUserId;
		}

		return $options;
	}

	private static function reportsTheActivityDeleted(Result $transferResult): bool
	{
		return (bool)($transferResult->getData()[self::DATA_IS_DELETED] ?? false);
	}

	/**
	 * The row of the bin the transfer created, or null where it named none - which a failed transfer never does, and a
	 * successful one skips where the activity was locked by another operation. Row 16 reads this off the run precisely
	 * because a failed transfer answers "no row" while the bin itself might still have one.
	 */
	private static function recyclingEntityIdOf(Result $transferResult): ?int
	{
		$recyclingEntityId = $transferResult->getData()[self::DATA_RECYCLING_ENTITY_ID] ?? null;

		return $recyclingEntityId === null ? null : (int)$recyclingEntityId;
	}

	//region the title of the provider

	/**
	 * Replaces the subject with the title the provider builds - on the model of the run and in the fields being
	 * handed over, which are the two things the rows below and the bin read.
	 *
	 * @param array<string, mixed> $fields
	 */
	private function retitle(PipelineContext $context, array &$fields): void
	{
		$title = $this->titleOf($context, $fields);
		if ($title === null)
		{
			return;
		}

		$fields[self::COLUMN_SUBJECT] = $title;
		$context->getActivity()->internalSet(Activity::subject, $title);
	}

	/**
	 * The title of the activity as its provider builds it, or null where there is no provider or where its class
	 * is no legacy one and therefore answers no such question.
	 *
	 * @param array<string, mixed> $fields
	 */
	private function titleOf(PipelineContext $context, array $fields): ?string
	{
		$provider = $context->getProvider();
		if ($provider === null)
		{
			return null;
		}

		$className = $this->registry()->getDescriptor($provider->getId())?->getClassName();
		if ($className === null || !is_a($className, LegacyProviderBase::class, true))
		{
			return null;
		}

		return $className::getActivityTitle($fields);
	}

	private function registry(): ProviderRegistryInterface
	{
		return $this->registry ?? ServiceLocator::getInstance()->get(ProviderRegistry::SERVICE_CODE);
	}

	//endregion

	//region the legacy shape

	/**
	 * The state of the run in the shape the recycle bin stores: EVERY column of the row but the two named above,
	 * plus the marker of the incoming channel and the identifiers of the files.
	 *
	 * THE WIDTH IS THE CONTRACT AND NOT A CONSEQUENCE. Legacy hands the whole read row over, so the slot carries a
	 * key for every column the row has - a column holding nothing included, because a restoration is built out of
	 * the intersection of the slot with the declared names and a missing key is a value a restoration cannot
	 * rebuild. So the list is walked by the field map rather than by what the carriers of the run happen to hold:
	 * a column of the core is answered by the model in its legacy form (which is where "no parent" becomes the zero
	 * of the column and "no deadline" the max-date sentinel), a serialized one by the payload, and everything else
	 * by the transit - with nothing where no carrier of V2 holds it.
	 *
	 * EVERY COLUMN HAS A CARRIER, `RESULT_VALUE` INCLUDED. It is hidden from every model of V2 (ALG-05, see the
	 * note of `MaterializeProviderStatistics`) and travels in the transit like the rest of the columns no model
	 * holds, so the slot carries the value the operation was given rather than an empty key. It has to: the
	 * snapshot of the recycle bin is what a restoration rebuilds the column out of
	 * (`lib/recycling/activitycontroller.php:54`), and the statistics of the communications re-aggregate the
	 * restored row (`lib/activity/communicationstatistics.php:404, :511`).
	 *
	 * @return array<string, mixed>
	 */
	private function legacyFieldsOf(PipelineContext $context): array
	{
		$activity = $context->getActivity();
		$transit = $context->getTransit()->getValues();
		$serialized = self::serializedColumnsOf($context->getPayload());

		$fields = [];
		foreach (ActivityFieldMap::getColumns() as $column)
		{
			if (
				$column === self::COLUMN_OUTSIDE_THE_SLOT
				|| $column === self::COLUMN_THE_READER_OF_LEGACY_CANNOT_SELECT
			)
			{
				continue;
			}

			$fields[$column] = match (true)
			{
				ActivityFieldMap::getFieldName($column) !== null => self::coreColumnOf($activity, $column),
				array_key_exists($column, $serialized) => $serialized[$column],
				default => self::legacyValueOf($transit[$column] ?? null),
			};
		}

		$isIncomingChannel = $activity->isIncomingChannel();
		if ($isIncomingChannel !== null)
		{
			$fields[self::COLUMN_IS_INCOMING_CHANNEL] = $isIncomingChannel ? 'Y' : 'N';
		}

		$fields[self::COLUMN_STORAGE_ELEMENT_IDS] = serialize(
			$this->storageElements->getElementIds($context->getActivityId()),
		);

		return $fields;
	}

	/**
	 * One column of the core as the row holds it. The model is the one answer about it even where the transit
	 * carries the same column: a state has one answer about the deadline and about the priority, and it is the
	 * answer of the model (see the note of {@see LoadActivity}).
	 */
	private static function coreColumnOf(Activity $activity, string $column): mixed
	{
		return self::legacyValueOf(ActivityFieldMap::toColumnValue(
			$column,
			self::fieldValueOf($activity, (string)ActivityFieldMap::getFieldName($column)),
		));
	}

	/**
	 * The three columns stored as one blob each, in the shape the storage boundary read them: the settings and the
	 * parameters of the provider unpacked, its data as the column holds it. That is the shape legacy reads them in
	 * too, and the shape a restoration hands back to the write path.
	 *
	 * @return array<string, mixed>
	 */
	private static function serializedColumnsOf(ProviderPayload $payload): array
	{
		$columns = [];
		foreach (ProviderPayload::getSupportedFields() as $field)
		{
			if ($payload->has($field))
			{
				$columns[ActivityFieldMap::getPayloadColumn($field)] = $payload->get($field);
			}
		}

		return $columns;
	}

	/**
	 * The value in the shape legacy carries rather than the shape the ORM writes: a flag is a letter and a moment
	 * is the text of the site.
	 */
	private static function legacyValueOf(mixed $value): mixed
	{
		if (is_bool($value))
		{
			return $value ? 'Y' : 'N';
		}

		return $value instanceof DateTime ? (string)$value : $value;
	}

	private static function fieldValueOf(Activity $activity, string $fieldName): mixed
	{
		return match ($fieldName)
		{
			Activity::id => $activity->getId(),
			Activity::providerId => $activity->getProviderId(),
			Activity::providerTypeId => $activity->getProviderTypeId(),
			Activity::subject => $activity->getSubject(),
			Activity::description => $activity->getDescription(),
			Activity::descriptionType => $activity->getDescriptionType(),
			Activity::isCompleted => $activity->isCompleted(),
			Activity::completionStatus => $activity->getCompletionStatus(),
			Activity::responsibleId => $activity->getResponsibleId(),
			Activity::authorId => $activity->getAuthorId(),
			Activity::editorId => $activity->getEditorId(),
			Activity::isImportant => $activity->isImportant(),
			Activity::direction => $activity->getDirection(),
			Activity::createdTime => $activity->getCreatedTime(),
			Activity::updatedTime => $activity->getUpdatedTime(),
			Activity::startTime => $activity->getStartTime(),
			Activity::endTime => $activity->getEndTime(),
			Activity::deadline => $activity->getDeadline(),
			Activity::parentActivityId => $activity->getParentActivityId(),
			Activity::autocompleteRule => $activity->getAutocompleteRule(),
			default => null,
		};
	}

	//endregion
}
