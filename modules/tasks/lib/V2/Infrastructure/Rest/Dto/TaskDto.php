<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToMany;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Mapping\TaskDtoMapper;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task\ChatDto;
use Bitrix\Tasks\V2\Internal\Entity\Task;

class TaskDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Editable]
	#[Required(['add'])]
	public ?string $title;

	#[Editable]
	public string $description;

	#[Required(['add'])]
	public int $creatorId;

	#[RelationToOne('creatorId', 'id')]
	public ?UserDto $creator;

	public ?DateTime $created;

	#[Editable]
	#[Required(['add'])]
	public int $responsibleId;

	#[RelationToOne('responsibleId', 'id')]
	public ?UserDto $responsible;

	#[Editable]
	public ?DateTime $deadline;

	#[Editable]
	public ?bool $needsControl;

	#[Editable]
	public ?DateTime $startPlan;

	#[Editable]
	public ?DateTime $endPlan;

	#[Editable]
	public ?array $fileIds;

	#[Editable]
	public ?array $checklist;

	#[Editable]
	public ?int $groupId;

	#[RelationToOne('groupId', 'id')]
	public ?GroupDto $group;

	#[Editable]
	public ?int $stageId;

	#[RelationToOne('stageId', 'id')]
	public ?StageDto $stage;

	#[Editable]
	public ?int $epicId;

	#[Editable]
	public ?int $storyPoints;

	#[Editable]
	public ?int $flowId;

	#[RelationToOne('flowId', 'id')]
	public ?FlowDto $flow;

	#[Editable]
	public ?string $priority;

	#[Editable]
	public ?string $status;

	#[Editable]
	public ?DateTime $statusChanged;

	#[ElementType(UserDto::class)]
	#[RelationToMany('id', 'id', ['id' => 'ASC'])]
	public ?DtoCollection $accomplices;

	#[ElementType(UserDto::class)]
	#[RelationToMany('id', 'id', ['id' => 'ASC'])]
	public ?DtoCollection $auditors;

	#[Editable]
	public ?int $parentId;

	#[RelationToOne('parentId', 'id')]
	public ?TaskDto $parent;

	#[Editable]
	public ?bool $containsChecklist;

	#[Editable]
	public ?bool $containsSubTasks;

	#[Editable]
	public ?bool $containsRelatedTasks;

	#[Editable]
	public ?bool $containsGanttLinks;

	#[Editable]
	public ?bool $containsPlacements;

	#[Editable]
	public ?bool $containsResults;

	#[Editable]
	public ?int $numberOfReminders;

	#[Editable]
	public ?int $chatId;

	#[RelationToOne('chatId', 'id')]
	public ?ChatDto $chat;

	#[Editable]
	public ?int $plannedDuration;

	#[Editable]
	public ?int $actualDuration;

	#[Editable]
	public ?string $durationType;

	#[Editable]
	public ?DateTime $started;

	#[Editable]
	public ?int $estimatedTime;

	#[Editable]
	public ?bool $replicate;

	#[Editable]
	public ?DateTime $changed;

	#[Editable]
	public ?int $changedById;

	#[RelationToOne('changedById', 'id')]
	public ?UserDto $changedBy;

	#[Editable]
	public ?int $statusChangedById;

	#[RelationToOne('statusChangedById', 'id')]
	public ?UserDto $statusChangedBy;

	#[Editable]
	public ?int $closedById;

	#[RelationToOne('closedById', 'id')]
	public ?UserDto $closedBy;

	#[Editable]
	public ?DateTime $closed;

	#[Editable]
	public ?DateTime $activity;

	#[Editable]
	public ?string $guid;

	#[Editable]
	public ?string $xmlId;

	#[Editable]
	public ?string $exchangeId;

	#[Editable]
	public ?string $exchangeModified;

	#[Editable]
	public ?int $outlookVersion;

	#[Editable]
	public ?string $mark;

	#[Editable]
	public ?bool $allowsChangeDeadline;

	#[Editable]
	public ?bool $allowsTimeTracking;

	#[Editable]
	public ?bool $matchesWorkTime;

	#[Editable]
	public ?bool $addInReport;

	#[Editable]
	public ?bool $isMultitask;

	#[Editable]
	public ?string $siteId;

	#[Editable]
	public ?int $forkedByTemplateId;

	#[RelationToOne('forkedByTemplateId', 'id')]
	public ?TemplateDto $forkedByTemplate;

	#[Editable]
	public ?int $deadlineCount;

	#[Editable]
	public ?string $declineReason;

	#[Editable]
	public ?int $forumTopicId;

	#[RelationToMany('id', 'id', ['id' => 'ASC'])]
	#[ElementType(TagDto::class)]
	public ?DtoCollection $tags;

	#[Editable]
	public ?string $link;

	#[ElementType(UserFieldDto::class)]
	#[RelationToMany('id', 'id', ['id' => 'ASC'])]
	public ?DtoCollection $userFields;

	#[Editable]
	public ?array $rights;

	#[Editable]
	public ?string $archiveLink;

	#[Editable]
	public ?array $crmItemIds;

	#[Editable]
	public ?array $reminders;

	public ?ElapsedTimeDto $elapsedTime;

	#[Editable]
	public ?bool $requireResult;

	#[Editable]
	public ?bool $matchesSubTasksTime;

	#[Editable]
	public ?bool $autocompleteSubTasks;

	#[Editable]
	public ?bool $allowsChangeDatePlan;

	#[Editable]
	public ?int $emailId;

	#[RelationToOne('emailId', 'id')]
	public ?EmailDto $email;

	#[Editable]
	public ?DateTime $maxDeadlineChangeDate;

	#[Editable]
	public ?int $maxDeadlineChanges;

	#[Editable]
	public ?bool $requireDeadlineChangeReason;

	#[Editable]
	public ?array $inFavorite;

	#[Editable]
	public ?array $inPin;

	#[Editable]
	public ?array $inGroupPin;

	#[Editable]
	public ?array $inMute;

	public ?SourceDto $source;

	#[Editable]
	public ?array $dependsOn;

	#[Editable]
	public ?array $scenarios;

	public static function fromEntity(?Task $task, ?Request $request = null): ?self
	{
		if (!$task)
		{
			return null;
		}

		return (new TaskDtoMapper())->mapByTaskAndRequest($task, $request);
	}
}
