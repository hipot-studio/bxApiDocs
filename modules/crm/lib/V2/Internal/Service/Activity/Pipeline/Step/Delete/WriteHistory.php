<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\Activity\Provider\Tasks\Task;
use Bitrix\Crm\Activity\Provider\ToDo\ToDo;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\HistoryAuthor;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use CCrmActivityType;
use CCrmContentType;
use CCrmEvent;
use CCrmOwnerType;
use CCrmSecurityHelper;
use CTextParser;

/**
 * Writes the removal of the activity into the history of the CRM: row 11 of the owner table STEP-DELETE
 * (crm_activity.php:1408-1423), checklist item C7.8.
 *
 * ONE RECORD PER OWNER, AND EVERY OWNER OWNS ITS OWN ROW. A creation writes one record and attaches the remaining
 * bindings to it as relations; a removal repeats the whole record for every object the activity was bound to
 * (:5704-5717). So the three pipelines share the name of the effect and nothing of the call, and each of them owns
 * its own class.
 *
 * THE SET OF BINDINGS IS THE ONE ROW 6 ANSWERED, and it is read out of the model of the run rather than out of
 * storage: the rows are gone by now, erased by row 8. The owner table gates the row on the demand of the caller
 * alone, which the closed answer of row 6 makes exact - a run that reached this far always has a set, empty or not,
 * and legacy iterates an empty set as well.
 *
 * WHAT THE RECORD SAYS. The kind of the activity names the phrase, and the text is the subject of the activity or
 * "[id]" where it has none; a to-do item titles the record itself and appends its description to that title
 * (:5695-5702). The description is rendered by its markup before it is appended, exactly as legacy renders it, and
 * for anything that is not a to-do item it takes no part in the record at all.
 *
 * THE SUBJECT MAY ALREADY BE THE TITLE OF THE PROVIDER. Row 5 overwrites it on the model where an activity went to
 * the recycle bin, and the history therefore shows that title - legacy mutates its field array in the same place and
 * the same way.
 *
 * THE PERSON THE RECORD NAMES IS THE USER OF THE SESSION, not the one the operation was given, and not the one the
 * row names either - a removal is the one of the three histories legacy does not resolve out of the activity
 * ({@see HistoryAuthor}). Legacy hands `CCrmSecurityHelper::GetCurrentUserID()` over here (:1445-1451) while it
 * passes `$options['CURRENT_USER']` to the restriction of row 3, and the two differ on every call made on behalf of
 * somebody else. Where the session names nobody the record falls back to the explicitly set user of the context,
 * which is the fallback of the writer of the history itself (:6338-6345).
 *
 * THE PERMISSIONS OF THE OPERATION REACH THE WRITER OF THE HISTORY. It is the one effect of the lower half of a
 * delete that is told about them, and legacy passes the very argument of its call (:1421): a record of an object the
 * acting user may not see is refused by the writer.
 *
 * @internal
 */
final class WriteHistory implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The text of
	 * a record names the activity, so there is nothing to record.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	private const EVENT_TYPE_HISTORY = 1;

	private const ENTITY_FIELD = 'ACTIVITIES';

	/** The name of the kind of a record for an activity that is none of the four legacy kinds. */
	private const DEFAULT_EVENT_KIND = 'EVENT';

	private const COLUMN_TYPE_ID = 'TYPE_ID';

	public function getName(): string
	{
		return 'WriteHistory';
	}

	/**
	 * An operation the caller lets write a record of the history. It is the one and only thing that argument of
	 * legacy governs (item A4 of LBS-1).
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

		$record = self::recordOf($context, $activityId);
		$checkPermissions = $context->getOperationContext()->shouldCheckPermissions();

		foreach ($context->getActivity()->getBindings() ?? [] as $binding)
		{
			self::write($record, $binding, $checkPermissions);
		}

		return $result;
	}

	/**
	 * An activity whose removal could not be recorded is deleted all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The record of the removal without the owner of it, which is the only thing telling one row from another.
	 *
	 * @return array<string, mixed>
	 */
	private static function recordOf(PipelineContext $context, int $activityId): array
	{
		self::loadThePhrases();

		$activity = $context->getActivity();

		return [
			'EVENT_NAME' => (string)Loc::getMessage(
				'CRM_ACTIVITY_' . self::kindOf($activity, $context->getTransit()) . '_REMOVE',
			),
			'EVENT_TEXT_1' => self::textOf($activity, $activityId),
			'EVENT_TEXT_2' => '',
			'EVENT_TYPE' => self::EVENT_TYPE_HISTORY,
			'ENTITY_FIELD' => self::ENTITY_FIELD,
			'USER_ID' => self::actingUserId(),
		];
	}

	/**
	 * One record against one owner of the activity.
	 *
	 * @param array<string, mixed> $record
	 */
	private static function write(array $record, ActivityBinding $binding, bool $checkPermissions): void
	{
		$record['ENTITY_TYPE'] = CCrmOwnerType::ResolveName($binding->getOwnerType()->getId());
		$record['ENTITY_ID'] = $binding->getOwnerId();

		(new CCrmEvent())->Add($record, $checkPermissions);
	}

	/**
	 * The text of the record: the subject of the activity, or its identifier where it has none. A to-do item is
	 * titled by its own provider and carries its description behind that title (:5695-5702).
	 */
	private static function textOf(Activity $activity, int $activityId): string
	{
		$subject = (string)$activity->getSubject();
		$text = $subject === '' ? "[{$activityId}]" : $subject;

		if ((string)$activity->getProviderId() !== ToDo::getId())
		{
			return $text;
		}

		$title = ToDo::getActivityTitle([
			'SUBJECT' => $subject,
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
		]);
		$description = self::renderedDescriptionOf($activity);

		return $description === '' ? $title : $title . ': ' . $description;
	}

	/**
	 * The description as the history renders it: the bb-code of a text is rendered, the plain text and the html of
	 * it are stored as they are.
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
	 * The name of the kind of the record, resolved over the whole legacy list of kinds: a delete works on rows
	 * legacy wrote too, and the legacy kind of such a row can be a call, a meeting, a task or a letter
	 * (:5371-5399). A combination the language file has no phrase for leaves the record unnamed, which is what
	 * legacy produces there as well.
	 */
	private static function kindOf(Activity $activity, StorageFieldTransit $transit): string
	{
		$typeId = $transit->has(self::COLUMN_TYPE_ID)
			? (int)$transit->get(self::COLUMN_TYPE_ID)
			: CCrmActivityType::Undefined
		;
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

	/**
	 * The user of the session, with the explicitly set user of the context behind it. See the note of the class.
	 */
	private static function actingUserId(): int
	{
		return HistoryAuthor::orTheExplicitUser((int)CCrmSecurityHelper::GetCurrentUserID());
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
