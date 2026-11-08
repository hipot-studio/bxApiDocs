<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Activity\Provider\Tasks\Task;
use Bitrix\Crm\Activity\Provider\ToDo\ToDo;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Integration\Calendar\ActivityCalendarSync;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\HistoryAuthor;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use CCrmActivityDirection;
use CCrmActivityNotifyType;
use CCrmActivityPriority;
use CCrmActivityStatus;
use CCrmActivityType;
use CCrmContentType;
use CCrmEvent;
use CCrmOwnerType;
use CSite;
use CTextParser;
use CUser;

/**
 * Writes what the update changed into the history of the CRM: row 37 of the owner table STEP-UPDATE
 * (crm_activity.php:976-983), checklist item C7.8.
 *
 * ONE RECORD PER CHANGED FIELD PER OWNER, WHICH IS THE OPPOSITE OF AN ADD. A creation is one record owned by the
 * first binding with the remaining ones attached as relations; an update writes a record of its own for every field
 * of a fixed list that really moved, and repeats the whole set for every owner the activity is bound to. So the same
 * three pipelines share the name of the effect and nothing of the call, and each of them owns its own class.
 *
 * THE LIST OF FIELDS IS CLOSED AND ITS ORDER IS THE ORDER OF THE RECORDS ({@see self::comparisonsOf()}): the
 * subject, the two moments, the completion, the priority, the reminder, the description, the location, the direction
 * and the person responsible. Everything else an update can move - the provider, the bindings, the files, the
 * results of the provider - the history of legacy does not record, and adding one here would be a change of what
 * every portal shows.
 *
 * WHAT IS COMPARED IS NOT WHAT IS SHOWN. Legacy compares the two values as the columns carry them and only then
 * renders each side for a human: the completion becomes the name of a status, the priority and the direction the
 * name of a grade, the person responsible a formatted name, the location the text the calendar spells it out as,
 * a description in bb-code its rendered form. The rendering therefore happens for a field that moved and for no
 * other - the person responsible costs a query and the location a call into another module - and the comparison
 * stays on the raw values, where 'Y' against 'N' is a change and two identical texts are not.
 *
 * BOTH SIDES ARE STATES, as everywhere below row 17 (row D1 of the registry): the state read before the write
 * against the re-read one. The subject of a to-do item is left out of the comparison entirely, because it is
 * generated rather than written by a person (:5650-5653).
 *
 * THE TEXTS ARE COMPARED IN THE SHAPE BOTH SIDES REALLY HAVE. Row EMOJI-DECODED-AFTER-RELOAD of the registry of
 * intentional deviations names this row among the consumers seeing the emoji unescaped, but the reader of legacy
 * unescapes them on the way out of storage as well (`CCrmActivityDbResult::fetchOneRecord`), so both states of
 * legacy carry the same unescaped text here. There is nothing to reconcile in this row.
 *
 * THE KIND OF THE RECORD IS RESOLVED OVER THE WHOLE LEGACY LIST OF KINDS, unlike on an add. An update works on rows
 * that legacy wrote too, and the legacy kind of such a row can be a call, a meeting, a task or a letter; the add of
 * V2 only ever produces a provider activity, which is why its own step resolves two kinds and this one five
 * (:5371-5398). A combination the language file has no phrase for leaves the record unnamed, which is what legacy
 * produces there as well.
 *
 * EVERY RECORD OF ONE RUN NAMES THE SAME AUTHOR, and the re-read state is what names it ({@see HistoryAuthor}):
 * legacy resolves it out of that very state for each of the records it prepares (:6001), so resolving it once
 * here is the same answer and not a shortcut.
 *
 * @internal
 */
final class WriteHistory implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. A record
	 * names the activity it is about, so there is nothing to record.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The run reached this step without the state the activity was in. A record of an update is a difference, and
	 * there is nothing to compare against.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	private const EVENT_TYPE_HISTORY = 1;

	private const ENTITY_FIELD = 'ACTIVITIES';

	/** The name of the kind of a record for an activity that is none of the four legacy kinds. */
	private const DEFAULT_EVENT_KIND = 'EVENT';

	private const COLUMN_LOCATION = 'LOCATION';

	private const COLUMN_TYPE_ID = 'TYPE_ID';

	private const COLUMN_NOTIFY_TYPE = 'NOTIFY_TYPE';

	private const COLUMN_NOTIFY_VALUE = 'NOTIFY_VALUE';

	private const COLUMN_PRIORITY = 'PRIORITY';

	private readonly ActivityCalendarSync $calendar;

	/**
	 * @param ActivityCalendarSync|null $calendar the calendar side of an activity, asked to spell the location of
	 *        it out; the one seam of the step.
	 */
	public function __construct(?ActivityCalendarSync $calendar = null)
	{
		$this->calendar = $calendar ?? new ActivityCalendarSync();
	}

	public function getName(): string
	{
		return 'WriteHistory';
	}

	/**
	 * An operation the caller lets write a record of the history. It is the one and only thing that argument of
	 * legacy governs (item A4 of LBS-1), and an update has no second gate on it - a restoration is an add.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return $context->getOperationContext()->shouldRegisterHistoryEvent();
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		if (!$context->hasPreviousState())
		{
			return $result->addError(new Error(
				'The previous state of the activity was not loaded.',
				self::ERROR_PREVIOUS_STATE_NOT_LOADED,
			));
		}

		$records = $this->recordsOf($context, $activityId);
		if ($records === [])
		{
			return $result;
		}

		// An activity of V2 is never bound to nothing (row BINDINGS-EMPTY); legacy iterates an empty set as well.
		foreach ($context->getActivity()->getBindings() ?? [] as $binding)
		{
			foreach ($records as $record)
			{
				self::write($record, $binding);
			}
		}

		return $result;
	}

	/**
	 * An activity whose change could not be recorded is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The record of every field of the list that moved, in the order of the list.
	 *
	 * @return array<int, array<string, mixed>> records without the owner of each
	 */
	private function recordsOf(PipelineContext $context, int $activityId): array
	{
		self::loadThePhrases();

		$activity = $context->getActivity();
		$name = (string)$activity->getSubject() === '' ? "[{$activityId}]" : (string)$activity->getSubject();
		$kind = self::kindOf($activity, $context->getTransit());
		$authorId = HistoryAuthor::orTheExplicitUser(HistoryAuthor::ofTheRow($activity));

		$records = [];
		foreach ($this->comparisonsOf($context) as $field => [$previous, $current, $render])
		{
			if (strcmp($previous, $current) === 0)
			{
				continue;
			}

			[$previousText, $currentText] = $render === null ? [$previous, $current] : $render();

			$records[] = [
				'EVENT_NAME' => (string)Loc::getMessage(
					'CRM_ACTIVITY_CHANGE_' . $kind . '_' . $field,
					['#NAME#' => $name],
				),
				'EVENT_TEXT_1' => self::textOrEmptiness($previousText),
				'EVENT_TEXT_2' => self::textOrEmptiness($currentText),
				'EVENT_TYPE' => self::EVENT_TYPE_HISTORY,
				'ENTITY_FIELD' => self::ENTITY_FIELD,
				'USER_ID' => $authorId,
			];
		}

		return $records;
	}

	/**
	 * The closed list of the fields of the history, each as "the previous value, the current one, and how the two
	 * are spelled out for a human". A renderer of null means the raw values are the text.
	 *
	 * @return array<string, array{0: string, 1: string, 2: (callable(): array{0: string, 1: string})|null}>
	 */
	private function comparisonsOf(PipelineContext $context): array
	{
		$previous = $context->getPreviousActivity();
		$activity = $context->getActivity();
		$previousTransit = $context->getPreviousTransit();
		$transit = $context->getTransit();

		$typeId = self::typeIdOf($transit);
		$previousTypeId = self::typeIdOf($previousTransit);

		$comparisons = [];

		if ((string)$activity->getProviderId() !== ToDo::getId())
		{
			$comparisons['SUBJECT'] = [(string)$previous->getSubject(), (string)$activity->getSubject(), null];
		}

		$comparisons['START_TIME'] = [
			self::momentText($previous->getStartTime()),
			self::momentText($activity->getStartTime()),
			null,
		];
		$comparisons['END_TIME'] = [
			self::momentText($previous->getEndTime()),
			self::momentText($activity->getEndTime()),
			null,
		];

		$previousCompletion = $previous->isCompleted() === true ? 'Y' : 'N';
		$completion = $activity->isCompleted() === true ? 'Y' : 'N';
		$comparisons['COMPLETED'] = [
			$previousCompletion,
			$completion,
			static fn(): array => [
				self::statusText($previousCompletion, $previousTypeId),
				self::statusText($completion, $typeId),
			],
		];

		$previousPriority = self::priorityText($previous, $previousTransit);
		$priority = self::priorityText($activity, $transit);
		$comparisons['PRIORITY'] = [
			$previousPriority,
			$priority,
			static fn(): array => [
				(string)CCrmActivityPriority::ResolveDescription($previousPriority),
				(string)CCrmActivityPriority::ResolveDescription($priority),
			],
		];

		$previousNotify = self::notifyPairOf($previousTransit);
		$notify = self::notifyPairOf($transit);
		$comparisons['NOTIFY'] = [
			implode(':', $previousNotify),
			implode(':', $notify),
			static fn(): array => [self::notifyText($previousNotify), self::notifyText($notify)],
		];

		$comparisons['DESCRIPTION'] = [
			(string)$previous->getDescription(),
			(string)$activity->getDescription(),
			static fn(): array => [self::descriptionText($previous), self::descriptionText($activity)],
		];

		$previousLocation = self::locationOf($previousTransit);
		$location = self::locationOf($transit);
		$comparisons['LOCATION'] = [
			$previousLocation,
			$location,
			fn(): array => [
				$this->calendar->textOfLocation($previousLocation),
				$this->calendar->textOfLocation($location),
			],
		];

		$previousDirection = self::columnText('DIRECTION', $previous->getDirection());
		$direction = self::columnText('DIRECTION', $activity->getDirection());
		$comparisons['DIRECTION'] = [
			$previousDirection,
			$direction,
			static fn(): array => [
				(string)CCrmActivityDirection::ResolveDescription($previousDirection, $typeId),
				(string)CCrmActivityDirection::ResolveDescription($direction, $typeId),
			],
		];

		$previousResponsible = (string)(int)$previous->getResponsibleId();
		$responsible = (string)(int)$activity->getResponsibleId();
		$comparisons['RESPONSIBLE_ID'] = [
			$previousResponsible,
			$responsible,
			static fn(): array => self::namesOf((int)$previousResponsible, (int)$responsible),
		];

		return $comparisons;
	}

	/**
	 * One record against one owner of the activity.
	 *
	 * @param array<string, mixed> $record
	 */
	private static function write(array $record, ActivityBinding $binding): void
	{
		$record['ENTITY_TYPE'] = CCrmOwnerType::ResolveName($binding->getOwnerType()->getId());
		$record['ENTITY_ID'] = $binding->getOwnerId();

		(new CCrmEvent())->Add($record, false);
	}

	/**
	 * The name of the kind of the record: the legacy kind of the activity, with the tasks of the module of tasks
	 * reachable through a provider as well (:5371-5398).
	 */
	private static function kindOf(Activity $activity, StorageFieldTransit $transit): string
	{
		$typeId = self::typeIdOf($transit);
		$isTaskProvider = $typeId === CCrmActivityType::Provider
			&& (string)$activity->getProviderId() === Task::getId()
		;

		return match (true)
		{
			$typeId === CCrmActivityType::Call => 'CALL',
			$typeId === CCrmActivityType::Meeting => 'MEETING',
			$typeId === CCrmActivityType::Task, $isTaskProvider => 'TASK',
			$typeId === CCrmActivityType::Email => 'EMAIL',
			default => self::DEFAULT_EVENT_KIND,
		};
	}

	private static function typeIdOf(StorageFieldTransit $transit): int
	{
		return $transit->has(self::COLUMN_TYPE_ID)
			? (int)$transit->get(self::COLUMN_TYPE_ID)
			: CCrmActivityType::Undefined
		;
	}

	/**
	 * A moment as the column of it carries it - the text of the site - or nothing where the state has none.
	 */
	private static function momentText(?DateTime $moment): string
	{
		return $moment === null ? '' : (string)$moment;
	}

	/**
	 * The value a column of the storage carries for a field of the model, as text.
	 */
	private static function columnText(string $column, mixed $fieldValue): string
	{
		return (string)(int)ActivityFieldMap::toColumnValue($column, $fieldValue);
	}

	/**
	 * The priority as text, in the shape legacy compares: the grade of the column when the caller named it in this
	 * very call - the transit of the run then carries what was written - and the flag of the model otherwise.
	 *
	 * A STATE READ FROM A ROW NEVER CARRIES THE COLUMN, so the previous side is always the flag. The transit of a
	 * stored state holds only what the model cannot
	 * ({@see \Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository::transitColumnsOfARow()}), and the model
	 * holds the priority as a flag telling the high grade from the other three. A move between two grades that flag
	 * reads alike - Medium to Low, none to Low - therefore looks unchanged here and is not recorded, where legacy
	 * records it naming the real grades. Registered divergence, row PRIORITY-HISTORY-BY-FLAG of the registry of
	 * intentional deviations; the boundary of the high grade is recorded exactly as legacy records it.
	 */
	private static function priorityText(Activity $activity, StorageFieldTransit $transit): string
	{
		return $transit->has(self::COLUMN_PRIORITY)
			? (string)(int)$transit->get(self::COLUMN_PRIORITY)
			: self::columnText(self::COLUMN_PRIORITY, $activity->isImportant())
		;
	}

	private static function statusText(string $completion, int $typeId): string
	{
		return (string)CCrmActivityStatus::ResolveDescription(
			$completion === 'Y' ? CCrmActivityStatus::Completed : CCrmActivityStatus::Waiting,
			$typeId,
		);
	}

	/**
	 * The reminder of a state as the pair of its two columns.
	 *
	 * @return array{0: int, 1: int}
	 */
	private static function notifyPairOf(StorageFieldTransit $transit): array
	{
		return [
			$transit->has(self::COLUMN_NOTIFY_TYPE)
				? (int)$transit->get(self::COLUMN_NOTIFY_TYPE)
				: CCrmActivityNotifyType::None,
			$transit->has(self::COLUMN_NOTIFY_VALUE) ? (int)$transit->get(self::COLUMN_NOTIFY_VALUE) : 0,
		];
	}

	/**
	 * The reminder for a human: the name of the unit alone where there is no reminder, the amount of units
	 * otherwise.
	 *
	 * @param array{0: int, 1: int} $notify
	 */
	private static function notifyText(array $notify): string
	{
		[$type, $value] = $notify;
		if ($type === CCrmActivityNotifyType::None)
		{
			return (string)CCrmActivityNotifyType::ResolveDescription(CCrmActivityNotifyType::None);
		}

		return $value . ' ' . CCrmActivityNotifyType::ResolveDescription($type);
	}

	/**
	 * The description of a state as the history stores it: the bb-code of a text is rendered, everything else is
	 * stored as it stands.
	 */
	private static function descriptionText(Activity $activity): string
	{
		$description = (string)$activity->getDescription();
		$markup = (int)ActivityFieldMap::toColumnValue('DESCRIPTION_TYPE', $activity->getDescriptionType());

		return $markup === CCrmContentType::BBCode
			? (new CTextParser())->convertText($description)
			: $description
		;
	}

	/**
	 * The location of a state, which the typed model does not hold: it travels as a column of the transit.
	 */
	private static function locationOf(StorageFieldTransit $transit): string
	{
		return $transit->has(self::COLUMN_LOCATION) ? (string)$transit->get(self::COLUMN_LOCATION) : '';
	}

	/**
	 * The names of the two people the activity passed between, formatted the way the portal formats a name. One
	 * query for both, as legacy asks it; a user that is not there formats to nothing.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function namesOf(int $previousUserId, int $userId): array
	{
		$users = [];
		$rows = CUser::GetList(
			'id',
			'asc',
			['ID' => "{$previousUserId}|{$userId}"],
			['FIELDS' => ['ID', 'LOGIN', 'EMAIL', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'TITLE']],
		);
		while (is_array($row = $rows->Fetch()))
		{
			$users[(int)$row['ID']] = $row;
		}

		$template = CSite::GetNameFormat(false);

		return [
			(string)CUser::FormatName($template, $users[$previousUserId] ?? []),
			(string)CUser::FormatName($template, $users[$userId] ?? []),
		];
	}

	/**
	 * A side of a record that has no text of its own says so in words: the history shows a placeholder rather
	 * than an empty cell.
	 */
	private static function textOrEmptiness(string $text): string
	{
		return $text === '' ? (string)Loc::getMessage('CRM_ACTIVITY_FIELD_COMPARE_EMPTY') : $text;
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
