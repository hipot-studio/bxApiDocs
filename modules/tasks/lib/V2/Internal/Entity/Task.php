<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Main\Type\Collection;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Tasks\V2\Internal\Entity\Task\Duration;
use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTime;
use Bitrix\Tasks\V2\Internal\Entity\Task\Link;
use Bitrix\Tasks\V2\Internal\Entity\Task\Mark;
use Bitrix\Tasks\V2\Internal\Entity\Task\Priority;
use Bitrix\Tasks\V2\Internal\Entity\Task\ReminderCollection;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;

class Task extends AbstractEntity
{
	public function __construct(
		public readonly ?int            $id = null,
		#[NotEmpty(allowZero: true)]
		public readonly ?string         $title = null,
		public readonly ?string         $description = null,
		#[NotEmpty]
		#[Validatable]
		public readonly ?User           $creator = null,
		public readonly ?int            $createdTs = null,
		#[NotEmpty]
		#[Validatable]
		public readonly ?User           $responsible = null,
		public readonly ?int            $deadlineTs = null,
		public readonly ?bool           $needsControl = null,
		public readonly ?int            $startPlanTs = null,
		public readonly ?int            $endPlanTs = null,
		public readonly ?array          $fileIds = null,
		public readonly ?array          $checklist = null,
		#[Validatable]
		public readonly ?Group          $group = null,
		public readonly ?Stage          $stage = null,
		#[Validatable]
		public readonly ?Flow           $flow = null,
		public readonly ?Priority       $priority = null,
		public readonly ?Status         $status = null,
		public readonly ?int            $statusChangedTs = null,
		#[Validatable]
		public readonly ?UserCollection $accomplices = null,
		#[Validatable]
		public readonly ?UserCollection $auditors = null,
		public readonly ?self           $parent = null,
		public readonly ?bool           $containsChecklist = null,
		public readonly ?int            $chatId = null,
		public readonly ?int            $plannedDuration = null,
		public readonly ?int            $actualDuration = null,
		public readonly ?Duration       $durationType = null,
		public readonly ?int            $startedTs = null,
		public readonly ?int            $estimatedTime = null,
		public readonly ?bool           $replicate = null,
		public readonly ?int            $changedTs = null,
		#[Validatable]
		public readonly ?User           $statusChangedBy = null,
		#[Validatable]
		public readonly ?User           $closedBy = null,
		public readonly ?int            $closedTs = null,
		public readonly ?int            $activityTs = null,
		public readonly ?string         $guid = null,
		public readonly ?string         $xmlId = null,
		public readonly ?string         $exchangeId = null,
		public readonly ?string         $exchangeModified = null,
		public readonly ?int            $outlookVersion = null,
		public readonly ?Mark           $mark = null,
		public readonly ?bool           $allowsChangeDeadline = null,
		public readonly ?bool           $allowsTimeTracking = null,
		public readonly ?bool           $matchesWorkTime = null,
		public readonly ?bool           $addInReport = null,
		public readonly ?bool           $isMultitask = null,
		public readonly ?string         $siteId = null,
		public readonly ?Template       $forkedByTemplate = null,
		public readonly ?int            $deadlineCount = null,
		public readonly ?bool           $isZombie = null,
		public readonly ?string         $declineReason = null,
		public readonly ?int            $forumTopicId = null,
		#[Validatable]
		public readonly ?TagCollection  $tags = null,
		public readonly ?Link           $link = null,
		public readonly ?UserFieldCollection $userFields = null,
		public readonly ?array $rights = null,
		public readonly ?array          $crmFields = null,
		public readonly ?ReminderCollection $reminders = null,
		public readonly ?ElapsedTime $elapsedTime = null,
	)
	{

	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getMemberIds(): array
	{
		$memberIds = array_merge(
			[$this->creator?->getId(), $this->responsible?->getId()],
			$this->accomplices?->getIds() ?? [],
			$this->auditors?->getIds() ?? []
		);

		Collection::normalizeArrayValuesByInt($memberIds);

		return $memberIds;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'description' => $this->description,
			'creator' => $this->creator?->toArray(),
			'createdTs' => $this->createdTs,
			'responsible' => $this->responsible?->toArray(),
			'deadlineTs' => $this->deadlineTs,
			'needsControl' => $this->needsControl,
			'startPlanTs' => $this->startPlanTs,
			'endPlanTs' => $this->endPlanTs,
			'fileIds' => $this->fileIds,
			'checklist' => $this->checklist,
			'group' => $this->group?->toArray(),
			'stage' => $this->stage?->toArray(),
			'flow' => $this->flow?->toArray(),
			'priority' => $this->priority?->value,
			'status' => $this->status?->value,
			'statusChangedTs' => $this->statusChangedTs,
			'accomplices' => $this->accomplices?->toArray(),
			'auditors' => $this->auditors?->toArray(),
			'parent' => $this->parent?->toArray(),
			'containsChecklist' => $this->containsChecklist,
			'chatId' => $this->chatId,
			'plannedDuration' => $this->plannedDuration,
			'actualDuration' => $this->actualDuration,
			'durationType' => $this->durationType?->value,
			'startedTs' => $this->startedTs,
			'estimatedTime' => $this->estimatedTime,
			'replicate' => $this->replicate,
			'changedTs' => $this->changedTs,
			'statusChangedBy' => $this->statusChangedBy?->toArray(),
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
			'link' => $this->link?->getValue(),
			'userFields' => $this->userFields?->toArray(),
			'rights' => $this->rights,
			'crmFields' => $this->crmFields,
			'reminders' => $this->reminders?->toArray(),
			'elapsedTime' => $this->elapsedTime?->toArray(),
		];
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id:                   $props['id'] ?? null,
			title:                $props['title'] ?? null,
			description:          $props['description'] ?? null,
			creator:              isset($props['creator']) && is_array($props['creator']) ? User::mapFromArray($props['creator']) : null,
			createdTs:            $props['createdTs'] ?? null,
			responsible:          isset($props['responsible']) && is_array($props['responsible']) ? User::mapFromArray($props['responsible']) : null,
			deadlineTs:           $props['deadlineTs'] ?? null,
			needsControl:         $props['needsControl'] ?? null,
			startPlanTs:          $props['startPlanTs'] ?? null,
			endPlanTs:            $props['endPlanTs'] ?? null,
			fileIds:              $props['fileIds'] ?? null,
			checklist:            isset($props['checklist']) && is_array($props['checklist']) ? $props['checklist']
				                      : null,
			group:                isset($props['group']) && is_array($props['group']) ? Group::mapFromArray($props['group']) : null,
			stage:                isset($props['stage']) && is_array($props['stage']) ? Stage::mapFromArray($props['stage']) : null,
			flow:                 isset($props['flow']) && is_array($props['flow']) ? Flow::mapFromArray($props['flow']) : null,
			priority:             Priority::tryFrom($props['priority'] ?? ''),
			status:               Status::tryFrom($props['status'] ?? ''),
			statusChangedTs:      $props['statusChangedTs'] ?? null,
			accomplices:          isset($props['accomplices']) && is_array($props['accomplices']) ? UserCollection::mapFromArray($props['accomplices'])
				                      : null,
			auditors:             isset($props['auditors']) && is_array($props['auditors']) ? UserCollection::mapFromArray($props['auditors']) : null,
			parent:               isset($props['parent']) && is_array($props['parent']) ? static::mapFromArray($props['parent']) : null,
			containsChecklist:    $props['containsChecklist'] ?? null,
			chatId:               $props['chatId'] ?? null,
			plannedDuration:      $props['plannedDuration'] ?? null,
			actualDuration:       $props['actualDuration'] ?? null,
			durationType:         isset($props['durationType']) ? Duration::tryFrom($props['durationType']) : null,
			startedTs:            $props['startedTs'] ?? null,
			estimatedTime:        $props['estimatedTime'] ?? null,
			replicate:            $props['replicate'] ?? null,
			changedTs:            $props['changedTs'] ?? null,
			statusChangedBy:      isset($props['statusChangedBy']) && is_array($props['statusChangedBy']) ? User::mapFromArray($props['statusChangedBy'])
				                      : null,
			closedBy:             isset($props['closedBy']) && is_array($props['closedBy']) ? User::mapFromArray($props['closedBy']) : null,
			closedTs:             $props['closedTs'] ?? null,
			activityTs:           $props['activityTs'] ?? null,
			guid:                 $props['guid'] ?? null,
			xmlId:                $props['xmlId'] ?? null,
			exchangeId:           $props['exchangeId'] ?? null,
			exchangeModified:     $props['exchangeModified'] ?? null,
			outlookVersion:       $props['outlookVersion'] ?? null,
			mark:                 isset($props['mark']) ? Mark::tryFrom($props['mark']) : null,
			allowsChangeDeadline: $props['allowsChangeDeadline'] ?? null,
			allowsTimeTracking:   $props['allowsTimeTracking'] ?? null,
			matchesWorkTime:      $props['matchesWorkTime'] ?? null,
			addInReport:          $props['addInReport'] ?? null,
			isMultitask:          $props['isMultitask'] ?? null,
			siteId:               $props['siteId'] ?? null,
			forkedByTemplate:     isset($props['forkedByTemplate']) && is_array($props['forkedByTemplate']) ? Template::mapFromArray($props['forkedByTemplate'])
				                      : null,
			deadlineCount:        $props['deadlineCount'] ?? null,
			isZombie:             $props['isZombie'] ?? null,
			declineReason:        $props['declineReason'] ?? null,
			forumTopicId:         $props['forumTopicId'] ?? null,
			tags:                 isset($props['tags']) && is_array($props['tags']) ? TagCollection::mapFromArray($props['tags']) : null,
			link:                 isset($props['link']) ? Link::mapFromValue($props['link']) : null,
			userFields:           isset($props['userFields']) && is_array($props['userFields']) ? UserFieldCollection::mapFromArray($props['userFields']) : null,
			rights:               $props['rights'] ?? null,
			crmFields:            $props['crmFields'] ?? null,
			reminders: 		  isset($props['reminders']) && is_array($props['reminders']) ? ReminderCollection::mapFromArray($props['reminders']) : null,
			elapsedTime:           isset($props['elapsedTime']) ? ElapsedTime::mapFromArray($props['elapsedTime']) : null,
		);
	}
}
