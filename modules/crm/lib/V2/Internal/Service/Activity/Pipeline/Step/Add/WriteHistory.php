<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Activity\Provider\Tasks\Task;
use Bitrix\Crm\Activity\Provider\ToDo\ToDo;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\HistoryAuthor;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\RestorationMode;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use CCrmActivityType;
use CCrmContentType;
use CCrmEvent;
use CCrmOwnerType;
use CTextParser;

/**
 * Writes the record of the creation of the activity into the history of the CRM: step 25 of the owner table of an
 * add (crm_activity.php:333-336), checklist item C7.8.
 *
 * ONE RECORD AND SEVERAL OWNERS OF IT. Legacy writes the record itself against the first binding of the activity
 * and attaches the remaining bindings to that one record as relations, so the history of every bound object shows
 * the creation while the text of it is stored once (:5557-5583). The order of the bindings therefore decides which
 * object owns the row, and the set is the effective one the write of the bindings left in the model - after the
 * deduplication, the filter and the appending of the owner (row 15 of STEP-ADD).
 *
 * IT IS AN ADD-ONLY STEP EVEN THOUGH ALL THREE PIPELINES HAVE ONE. An update records the difference between the
 * previous and the current row over a fixed list of fields, and a delete records a removal; the three share the
 * name of the effect and nothing of the call, so each pipeline owns its own class.
 *
 * TWO GATES, AND NEITHER IS ABOUT THE ACTIVITY. A restoration out of the recycle bin writes nothing - the row
 * being restored is not new to the history ({@see RestorationMode}) - and the caller may switch the history off
 * for the whole operation, which is the one and only thing that argument of legacy governs (item A4 of LBS-1).
 *
 * THE TEXT IS BUILT HERE, because there is no entry point of legacy to delegate it to: the method composing it is
 * private to the facade. It is the subject, the location and the description rendered by its markup, and the
 * provider of to-do items is a case of its own on both counts - its subject carries no caption and it titles the
 * whole record itself (:5586-5643). The phrases stay where their translations are, in the language file of the
 * legacy pipelines, so that the wording of a record does not fork.
 *
 * THE AUTHOR IS THE ONE THE ROW NAMES ({@see HistoryAuthor}), and the record and a relation ask for it not quite
 * identically, as legacy asks: the record falls back to the explicitly set user of the context (:6338-6345) while
 * a relation keeps the zero the row answered (:6031). Making the two one would be a change of whom the history
 * names on a call where the row names nobody.
 *
 * @internal
 */
final class WriteHistory implements PipelineStepInterface
{
	/**
	 * The record is written against the activity, so there is nothing to record before the row exists. Legacy
	 * cannot reach this state: it interrupts an add on a failed insert.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_HISTORY_WITHOUT_ACTIVITY';

	/**
	 * The record belongs to the objects the activity is bound to, and an activity of V2 is never bound to
	 * nothing - the check of the fields refuses one (row BINDINGS-EMPTY). Legacy reads a first binding that is
	 * not there and stores a record naming no object.
	 */
	public const ERROR_WITHOUT_BINDINGS = 'CRM_ACTIVITY_HISTORY_WITHOUT_BINDINGS';

	private const EVENT_TYPE_HISTORY = 1;

	private const ENTITY_FIELD = 'ACTIVITIES';

	/** The name of the kind of a record for an activity that is none of the four legacy kinds. */
	private const DEFAULT_EVENT_KIND = 'EVENT';

	private const COLUMN_LOCATION = 'LOCATION';

	private const COLUMN_TYPE_ID = 'TYPE_ID';

	public function getName(): string
	{
		return 'WriteHistory';
	}

	/**
	 * A creation the history has not seen before, and an operation the caller lets write one.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if (RestorationMode::isActive($context))
		{
			return false;
		}

		return $context->getOperationContext()->shouldRegisterHistoryEvent();
	}

	public function execute(PipelineContext $context): Result
	{
		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return (new Result())->addError(new Error('The activity has not been stored.', self::ERROR_NOT_STORED));
		}

		$bindings = array_values($context->getActivity()->getBindings() ?? []);
		if ($bindings === [])
		{
			return (new Result())->addError(
				new Error('The activity is bound to nothing.', self::ERROR_WITHOUT_BINDINGS),
			);
		}

		$history = new CCrmEvent();
		$recordId = self::createRecord($history, $context, array_shift($bindings));
		if ($recordId <= 0 || $bindings === [])
		{
			return new Result();
		}

		$history->AddRelation($recordId, self::relationsOf($context->getActivity(), $bindings), false);

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
	 * The record itself, owned by the first binding of the activity.
	 */
	private static function createRecord(CCrmEvent $history, PipelineContext $context, ActivityBinding $owner): int
	{
		$activity = $context->getActivity();

		$record = [
			'EVENT_NAME' => (string)Loc::getMessage(self::phraseOfTheKind($activity, $context->getTransit())),
			'EVENT_TEXT_1' => self::textOf($activity, $context->getTransit()),
			'EVENT_TEXT_2' => '',
			'EVENT_TYPE' => self::EVENT_TYPE_HISTORY,
			'ENTITY_TYPE' => CCrmOwnerType::ResolveName($owner->getOwnerType()->getId()),
			'ENTITY_ID' => $owner->getOwnerId(),
			'ENTITY_FIELD' => self::ENTITY_FIELD,
			'USER_ID' => HistoryAuthor::orTheExplicitUser(HistoryAuthor::ofTheRow($activity)),
		];

		return (int)$history->Add($record, false);
	}

	/**
	 * The remaining bindings as relations of the one record.
	 *
	 * @param ActivityBinding[] $bindings
	 * @return array<int, array<string, mixed>>
	 */
	private static function relationsOf(Activity $activity, array $bindings): array
	{
		$userId = HistoryAuthor::ofTheRow($activity);

		$relations = [];
		foreach ($bindings as $binding)
		{
			$relations[] = [
				'ENTITY_TYPE' => CCrmOwnerType::ResolveName($binding->getOwnerType()->getId()),
				'ENTITY_ID' => $binding->getOwnerId(),
				'ENTITY_FIELD' => self::ENTITY_FIELD,
				'USER_ID' => $userId,
			];
		}

		return $relations;
	}

	/**
	 * The phrase naming the kind of the record, resolved over the whole legacy list of kinds by the type of the
	 * activity (`ResolveEventTypeName`, :5687-5715), with the tasks of the module of tasks reachable through a
	 * provider as well. An add of V2 usually carries the provider type, but a caller naming a legacy type keeps
	 * it - the check of the fields substitutes the provider type only where none was given - and the history of
	 * such an activity has to say a call, a meeting or a letter, as every portal already shows.
	 */
	private static function phraseOfTheKind(Activity $activity, StorageFieldTransit $transit): string
	{
		self::loadThePhrases();

		$typeId = $transit->has(self::COLUMN_TYPE_ID)
			? (int)$transit->get(self::COLUMN_TYPE_ID)
			: CCrmActivityType::Undefined
		;
		$isTaskProvider = $typeId === CCrmActivityType::Provider
			&& (string)$activity->getProviderId() === Task::getId()
		;

		$kind = match (true)
		{
			$typeId === CCrmActivityType::Call => 'CALL',
			$typeId === CCrmActivityType::Meeting => 'MEETING',
			$typeId === CCrmActivityType::Task, $isTaskProvider => 'TASK',
			$typeId === CCrmActivityType::Email => 'EMAIL',
			default => self::DEFAULT_EVENT_KIND,
		};

		return 'CRM_ACTIVITY_' . $kind . '_ADD';
	}

	/**
	 * The text of the record: the caption of the subject unless the provider of to-do items titles it, the
	 * location, and the description rendered by its markup. A to-do item puts its own title in front of all of it,
	 * and stands alone where there is nothing else (:5592-5633).
	 */
	private static function textOf(Activity $activity, StorageFieldTransit $transit): string
	{
		self::loadThePhrases();

		$isToDo = (string)$activity->getProviderId() === ToDo::getId();
		$subject = (string)$activity->getSubject();
		$text = '';

		if ($subject !== '' && !$isToDo)
		{
			$text .= Loc::getMessage('CRM_ACTIVITY_SUBJECT') . ': ' . $subject . PHP_EOL;
		}

		$location = self::locationOf($transit);
		if ($location !== '')
		{
			$text .= Loc::getMessage('CRM_ACTIVITY_LOCATION') . ': ' . $location . PHP_EOL;
		}

		$text .= self::renderedDescriptionOf($activity);

		if (!$isToDo)
		{
			return $text;
		}

		$title = ToDo::getActivityTitle([
			'SUBJECT' => $subject,
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
		]);

		return $text === '' ? $title : $title . ': ' . $text;
	}

	/**
	 * The description as the history stores it: the bb-code of a text is rendered, the plain text and the html of
	 * it are stored as they are. Legacy has a fourth branch storing nothing, for a markup outside those three; the
	 * typed model has no way to say one, the map answering the plain text for anything it cannot name.
	 */
	private static function renderedDescriptionOf(Activity $activity): string
	{
		$description = (string)$activity->getDescription();
		$markup = (int)ActivityFieldMap::toColumnValue('DESCRIPTION_TYPE', $activity->getDescriptionType());

		return $markup === CCrmContentType::BBCode
			? (new CTextParser())->convertText($description)
			: $description
		;
	}

	/**
	 * The location of the activity, which the typed model does not hold: it travels as a column of the transit,
	 * and the step synchronizing the calendar - which stands below this one - is its other reader.
	 */
	private static function locationOf(StorageFieldTransit $transit): string
	{
		return $transit->has(self::COLUMN_LOCATION) ? (string)$transit->get(self::COLUMN_LOCATION) : '';
	}

	/**
	 * The phrases of a record belong to the legacy pipelines: the wording is what the history of every portal
	 * already shows, and a second copy of it here would fork the translations.
	 */
	private static function loadThePhrases(): void
	{
		Loc::loadLanguageFile(dirname(__DIR__, 8) . '/classes/general/crm_activity.php');
	}
}
