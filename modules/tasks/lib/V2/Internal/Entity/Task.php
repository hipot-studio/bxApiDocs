<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Tasks\V2\Internal\Entity\Task\Duration;
use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTime;
use Bitrix\Tasks\V2\Internal\Entity\Task\Gantt\LinkType;
use Bitrix\Tasks\V2\Internal\Entity\Task\Mark;
use Bitrix\Tasks\V2\Internal\Entity\Task\ReminderCollection;
use Bitrix\Tasks\V2\Internal\Entity\Task\ScenarioCollection;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;
use Bitrix\Tasks\V2\Internal\Entity\Task\TimerCollection;
use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemCollection;
use Bitrix\Tasks\V2\Internal\Entity\Task\Source;
use Bitrix\Tasks\V2\Internal\Integration\Im\Entity\Chat;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Entity\Email;
use Bitrix\Tasks\V2\Internal\Service\Task\Role;

/**
 * @method Task cloneWith(array $props)
 */
class Task extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		#[NotEmpty(allowZero: true)]
		public readonly ?string $title = null,
		public readonly ?string $description = null,
		public readonly ?string $descriptionChecksum = null,
		#[NotEmpty]
		#[Validatable]
		public readonly ?User $creator = null,
		public readonly ?int $createdTs = null,
		#[NotEmpty]
		#[Validatable]
		public readonly ?User $responsible = null,
		public readonly ?int $deadlineTs = null,
		public readonly ?bool $needsControl = null,
		public readonly ?int $startPlanTs = null,
		public readonly ?int $endPlanTs = null,
		public readonly ?array $fileIds = null,
		public readonly ?int $parentId = null,
		public readonly ?array $checklist = null,
		#[Validatable]
		public readonly ?Group $group = null,
		public readonly ?Stage $stage = null,
		public readonly ?int $epicId = null,
		public readonly ?string $storyPoints = null,
		#[Validatable]
		public readonly ?Flow $flow = null,
		public readonly ?Priority $priority = null,
		public readonly ?Status $status = null,
		public readonly ?int $statusChangedTs = null,
		#[Validatable]
		public readonly ?UserCollection $accomplices = null,
		#[Validatable]
		public readonly ?UserCollection $auditors = null,
		public readonly ?self $parent = null,
		public readonly ?bool $containsChecklist = null,
		public readonly ?bool $containsSubTasks = null,
		public readonly ?bool $containsRelatedTasks = null,
		public readonly ?bool $containsGanttLinks = null,
		public readonly ?bool $containsPlacements = null,
		public readonly ?bool $containsCommentFiles = null,
		public readonly ?int $numberOfReminders = null,
		public readonly ?TimerCollection $timers = null,
		public readonly ?int $timeSpent = null,
		public readonly ?int $chatId = null,
		public readonly ?Chat $chat = null,
		public readonly ?int $plannedDuration = null,
		public readonly ?int $actualDuration = null,
		public readonly ?Duration $durationType = null,
		public readonly ?int $startedTs = null,
		public readonly ?int $estimatedTime = null,
		public readonly ?bool $replicate = null,
		public readonly ?ReplicateParams $replicateParams = null,
		public readonly ?int $changedTs = null,
		#[Validatable]
		public readonly ?User $changedBy = null,
		#[Validatable]
		public readonly ?User $statusChangedBy = null,
		#[Validatable]
		public readonly ?User $closedBy = null,
		public readonly ?int $closedTs = null,
		public readonly ?int $activityTs = null,
		public readonly ?string $guid = null,
		public readonly ?string $xmlId = null,
		public readonly ?string $exchangeId = null,
		public readonly ?string $exchangeModified = null,
		public readonly ?int $outlookVersion = null,
		public readonly ?Mark $mark = null,
		public readonly ?bool $allowsChangeDeadline = null,
		public readonly ?bool $allowsTimeTracking = null,
		public readonly ?bool $matchesWorkTime = null,
		public readonly ?bool $addInReport = null,
		public readonly ?bool $isMultitask = null,
		public readonly ?string $siteId = null,
		public readonly ?Template $forkedByTemplate = null,
		public readonly ?int $deadlineCount = null,
		public readonly ?bool $isZombie = null,
		public readonly ?string $declineReason = null,
		public readonly ?int $forumTopicId = null,
		#[Validatable]
		public readonly ?TagCollection $tags = null,
		public readonly ?string $link = null,
		public readonly ?UserFieldCollection $userFields = null,
		public readonly ?array $rights = null,
		public readonly ?string $archiveLink = null,
		public readonly ?array $crmItemIds = null,
		public readonly ?CrmItemCollection $crmItems = null,
		public readonly ?ReminderCollection $reminders = null,
		public readonly ?ElapsedTime $elapsedTime = null,
		public readonly ?int $numberOfElapsedTimes = null,
		public readonly ?bool $requireResult = null,
		public readonly ?bool $matchesSubTasksTime = null,
		public readonly ?bool $autocompleteSubTasks = null,
		public readonly ?bool $allowsChangeDatePlan = null,
		public readonly ?array $inFavorite = null,
		public readonly ?array $inPin = null,
		public readonly ?array $inGroupPin = null,
		public readonly ?array $inMute = null,
		public readonly ?Source $source = null,
		public readonly ?array $dependsOn = null,
		public readonly ?array $ganttLinks = null,
		public readonly ?bool $containsResults = null,
		public readonly ?Email $email = null,
		public readonly ?DateTime $maxDeadlineChangeDate = null,
		public readonly ?int $maxDeadlineChanges = null,
		public readonly ?bool $requireDeadlineChangeReason = null,
		public ?string $deadlineChangeReason = null,
		public readonly ?UserCollection $multiResponsibles = null,
		public ?ScenarioCollection $scenarios = null,
	) {

	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getMembers(
		array $roles = [Role::Creator, Role::Responsible, Role::Accomplice, Role::Auditor]
	): UserCollection
	{
		$members = new UserCollection();
		if ($this->creator !== null && in_array(Role::Creator, $roles, true))
		{
			$members->add($this->creator);
		}
		if ($this->responsible !== null && in_array(Role::Responsible, $roles, true))
		{
			$members->add($this->responsible);
		}
		if ($this->accomplices !== null && in_array(Role::Accomplice, $roles, true))
		{
			$members->merge($this->accomplices);
		}
		if ($this->auditors !== null && in_array(Role::Auditor, $roles, true))
		{
			$members->merge($this->auditors);
		}

		return $members->unique();
	}

	public function getMemberIds(): array
	{
		return $this->getMembers()->getIdList();
	}

	public static function mapFromArray(array $props): static
	{
		if (isset($props['maxDeadlineChangeDate']) && is_string($props['maxDeadlineChangeDate']))
		{
			try
			{
				$props['maxDeadlineChangeDate'] = new DateTime($props['maxDeadlineChangeDate']);
			}
			catch (\Exception) {}
		}

		return new static(
			id: static::mapInteger($props, 'id'),
			title: static::mapString($props, 'title'),
			description: static::mapString($props, 'description'),
			descriptionChecksum: static::mapString($props, 'descriptionChecksum'),
			creator: static::mapEntity($props, 'creator', User::class),
			createdTs: static::mapInteger($props, 'createdTs'),
			responsible: static::mapEntity($props, 'responsible', User::class),
			deadlineTs: static::mapInteger($props, 'deadlineTs'),
			needsControl: static::mapBool($props, 'needsControl'),
			startPlanTs: static::mapInteger($props, 'startPlanTs'),
			endPlanTs: static::mapInteger($props, 'endPlanTs'),
			fileIds: static::mapArray($props, 'fileIds'),
			parentId: static::mapInteger($props, 'parentId'),
			checklist: static::mapArray($props, 'checklist'),
			group: static::mapEntity($props, 'group', Group::class),
			stage: static::mapEntity($props, 'stage', Stage::class),
			epicId: static::mapInteger($props, 'epicId'),
			storyPoints: static::mapString($props, 'storyPoints'),
			flow: static::mapEntity($props, 'flow', Flow::class),
			priority: static::mapBackedEnum($props, 'priority', Priority::class),
			status: static::mapBackedEnum($props, 'status', Status::class),
			statusChangedTs: static::mapInteger($props, 'statusChangedTs'),
			accomplices: static::mapEntityCollection($props, 'accomplices', UserCollection::class),
			auditors: static::mapEntityCollection($props, 'auditors', UserCollection::class),
			parent: static::mapEntity($props, 'parent', self::class),
			containsChecklist: static::mapBool($props, 'containsChecklist'),
			containsSubTasks: static::mapBool($props, 'containsSubTasks'),
			containsRelatedTasks: static::mapBool($props, 'containsRelatedTasks'),
			containsGanttLinks: static::mapBool($props, 'containsGanttLinks'),
			containsPlacements: static::mapBool($props, 'containsPlacements'),
			containsCommentFiles: static::mapBool($props, 'containsCommentFiles'),
			numberOfReminders: static::mapInteger($props, 'numberOfReminders'),
			timers: static::mapEntityCollection($props, 'timers', TimerCollection::class),
			timeSpent: static::mapInteger($props, 'timeSpent'),
			chatId: static::mapInteger($props, 'chatId'),
			chat: static::mapEntity($props, 'chat', Chat::class),
			plannedDuration: static::mapInteger($props, 'plannedDuration'),
			actualDuration: static::mapInteger($props, 'actualDuration'),
			durationType: static::mapBackedEnum($props, 'durationType', Duration::class),
			startedTs: static::mapInteger($props, 'startedTs'),
			estimatedTime: static::mapInteger($props, 'estimatedTime'),
			replicate: static::mapBool($props, 'replicate'),
			replicateParams: static::mapValueObject($props, 'replicateParams', ReplicateParams::class),
			changedTs: static::mapInteger($props, 'changedTs'),
			changedBy: static::mapEntity($props, 'changedBy', User::class),
			statusChangedBy: static::mapEntity($props, 'statusChangedBy', User::class),
			closedBy: static::mapEntity($props, 'closedBy', User::class),
			closedTs: static::mapInteger($props, 'closedTs'),
			activityTs: static::mapInteger($props, 'activityTs'),
			guid: static::mapString($props, 'guid'),
			xmlId: static::mapString($props, 'xmlId'),
			exchangeId: static::mapString($props, 'exchangeId'),
			exchangeModified: static::mapString($props, 'exchangeModified'),
			outlookVersion: static::mapInteger($props, 'outlookVersion'),
			mark: static::mapBackedEnum($props, 'mark', Mark::class),
			allowsChangeDeadline: static::mapBool($props, 'allowsChangeDeadline'),
			allowsTimeTracking: static::mapBool($props, 'allowsTimeTracking'),
			matchesWorkTime: static::mapBool($props, 'matchesWorkTime'),
			addInReport: static::mapBool($props, 'addInReport'),
			isMultitask: static::mapBool($props, 'isMultitask'),
			siteId: static::mapString($props, 'siteId'),
			forkedByTemplate: static::mapEntity($props, 'forkedByTemplate', Template::class),
			deadlineCount: static::mapInteger($props, 'deadlineCount'),
			isZombie: static::mapBool($props, 'isZombie'),
			declineReason: static::mapString($props, 'declineReason'),
			forumTopicId: static::mapInteger($props, 'forumTopicId'),
			tags: static::mapEntityCollection($props, 'tags', TagCollection::class),
			link: static::mapString($props, 'link'),
			userFields: static::mapEntityCollection($props, 'userFields', UserFieldCollection::class),
			rights: static::mapArray($props, 'rights'),
			archiveLink: static::mapString($props, 'archiveLink'),
			crmItemIds: static::mapArray($props, 'crmItemIds'),
			crmItems: static::mapEntityCollection($props, 'crmItems', CrmItemCollection::class),
			reminders: static::mapEntityCollection($props, 'reminders', ReminderCollection::class),
			elapsedTime: static::mapEntity($props, 'elapsedTime', ElapsedTime::class),
			numberOfElapsedTimes: static::mapInteger($props, 'numberOfElapsedTimes'),
			requireResult: static::mapBool($props, 'requireResult'),
			matchesSubTasksTime: static::mapBool($props, 'matchesSubTasksTime'),
			autocompleteSubTasks: static::mapBool($props, 'autocompleteSubTasks'),
			allowsChangeDatePlan: static::mapBool($props, 'allowsChangeDatePlan'),
			inFavorite: static::mapArray($props, 'inFavorite'),
			inPin: static::mapArray($props, 'inPin'),
			inGroupPin: static::mapArray($props, 'inGroupPin'),
			inMute: static::mapArray($props, 'inMute'),
			source: static::mapValueObject($props, 'source', Source::class),
			dependsOn: static::mapArray($props, 'dependsOn', 'intval'),
			ganttLinks: static::mapArray($props, 'ganttLinks', static fn (mixed $value): ?LinkType => is_string($value) ? LinkType::tryFrom($value) : null),
			containsResults: static::mapBool($props, 'containsResults'),
			email: static::mapEntity($props, 'email', Email::class),
			maxDeadlineChangeDate: static::mapDateTime($props, 'maxDeadlineChangeDate'),
			maxDeadlineChanges: static::mapInteger($props, 'maxDeadlineChanges'),
			requireDeadlineChangeReason: static::mapBool($props, 'requireDeadlineChangeReason'),
			deadlineChangeReason: static::mapString($props, 'deadlineChangeReason'),
			multiResponsibles: static::mapEntityCollection($props, 'multiResponsibles', UserCollection::class),
			scenarios: static::mapBackedEnumCollection($props, 'scenarios', ScenarioCollection::class),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'description' => $this->description,
			'descriptionChecksum' => $this->descriptionChecksum,
			'creator' => $this->creator?->toArray(),
			'createdTs' => $this->createdTs,
			'responsible' => $this->responsible?->toArray(),
			'deadlineTs' => $this->deadlineTs,
			'needsControl' => $this->needsControl,
			'startPlanTs' => $this->startPlanTs,
			'endPlanTs' => $this->endPlanTs,
			'fileIds' => $this->fileIds,
			'parentId' => $this->parentId,
			'checklist' => $this->checklist,
			'group' => $this->group?->toArray(),
			'stage' => $this->stage?->toArray(),
			'epicId' => $this->epicId,
			'storyPoints' => $this->storyPoints,
			'flow' => $this->flow?->toArray(),
			'priority' => $this->priority?->value,
			'status' => $this->status?->value,
			'statusChangedTs' => $this->statusChangedTs,
			'accomplices' => $this->accomplices?->toArray(),
			'auditors' => $this->auditors?->toArray(),
			'parent' => $this->parent?->toArray(),
			'containsChecklist' => $this->containsChecklist,
			'containsSubTasks' => $this->containsSubTasks,
			'containsRelatedTasks' => $this->containsRelatedTasks,
			'containsGanttLinks' => $this->containsGanttLinks,
			'containsPlacements' => $this->containsPlacements,
			'containsCommentFiles' => $this->containsCommentFiles,
			'numberOfReminders' => $this->numberOfReminders,
			'chatId' => $this->chatId,
			'chat' => $this->chat?->toArray(),
			'plannedDuration' => $this->plannedDuration,
			'actualDuration' => $this->actualDuration,
			'durationType' => $this->durationType?->value,
			'startedTs' => $this->startedTs,
			'estimatedTime' => $this->estimatedTime,
			'replicate' => $this->replicate,
			'replicateParams' => $this->replicateParams,
			'changedTs' => $this->changedTs,
			'statusChangedBy' => $this->statusChangedBy?->toArray(),
			'changedBy' => $this->changedBy?->toArray(),
			'closedBy' => $this->closedBy?->toArray(),
			'closedTs' => $this->closedTs,
			'activityTs' => $this->activityTs,
			'guid' => $this->guid,
			'xmlId' => $this->xmlId,
			'exchangeId' => $this->exchangeId,
			'exchangeModified' => $this->exchangeModified,
			'outlookVersion' => $this->outlookVersion,
			'mark' => $this->mark?->value,
			'allowsChangeDeadline' => $this->allowsChangeDeadline,
			'allowsTimeTracking' => $this->allowsTimeTracking,
			'matchesWorkTime' => $this->matchesWorkTime,
			'addInReport' => $this->addInReport,
			'isMultitask' => $this->isMultitask,
			'siteId' => $this->siteId,
			'forkedByTemplate' => $this->forkedByTemplate?->toArray(),
			'deadlineCount' => $this->deadlineCount,
			'isZombie' => $this->isZombie,
			'declineReason' => $this->declineReason,
			'forumTopicId' => $this->forumTopicId,
			'tags' => $this->tags?->toArray(),
			'link' => $this->link,
			'userFields' => $this->userFields?->toArray(),
			'rights' => $this->rights,
			'archiveLink' => $this->archiveLink,
			'crmItemIds' => $this->crmItemIds,
			'crmItems' => $this->crmItems?->toArray(),
			'reminders' => $this->reminders?->toArray(),
			'elapsedTime' => $this->elapsedTime?->toArray(),
			'timers' => $this->timers,
			'timeSpent' => $this->timeSpent,
			'numberOfElapsedTimes' => $this->numberOfElapsedTimes,
			'requireResult' => $this->requireResult,
			'matchesSubTasksTime' => $this->matchesSubTasksTime,
			'autocompleteSubTasks' => $this->autocompleteSubTasks,
			'allowsChangeDatePlan' => $this->allowsChangeDatePlan,
			'inFavorite' => $this->inFavorite,
			'inPin' => $this->inPin,
			'inGroupPin' => $this->inGroupPin,
			'inMute' => $this->inMute,
			'source' => $this->source?->toArray(),
			'dependsOn' => $this->dependsOn,
			'ganttLinks' => $this->ganttLinks,
			'containsResults' => $this->containsResults,
			'email' => $this->email?->toArray(),
			'maxDeadlineChangeDate' => $this->maxDeadlineChangeDate?->format(DateTime::getFormat()),
			'maxDeadlineChanges' => $this->maxDeadlineChanges,
			'requireDeadlineChangeReason' => $this->requireDeadlineChangeReason,
			'deadlineChangeReason' => $this->deadlineChangeReason,
			'multiResponsibles' => $this->multiResponsibles?->toArray(),
			'scenarios' => $this->scenarios?->toArray(),
		];
	}
}
