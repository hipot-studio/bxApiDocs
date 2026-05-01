<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\TaskList;

enum FieldsEnum: string
{
	case Id = 'id';
	case Title = 'title';
	case DateStart = 'dateStart';
	case CreatedDate = 'createdDate';
	case ChangedDate = 'changedDate';
	case ClosedDate = 'closedDate';
	case ActivityDate = 'activityDate';
	case StartDatePlan = 'startDatePlan';
	case EndDatePlan = 'endDatePlan';
	case Deadline = 'deadline';
	case Status = 'status';
	case RealStatus = 'realStatus';
	case StatusComplete = 'statusComplete';
	case Priority = 'priority';
	case Mark = 'mark';
	case OriginatorName = 'originatorName';
	case CreatedBy = 'createdBy';
	case CreatedByLastName = 'createdByLastName';
	case ResponsibleName = 'responsibleName';
	case ResponsibleId = 'responsibleId';
	case ResponsibleLastName = 'responsibleLastName';
	case GroupId = 'groupId';
	case Group = 'group';
	case ComputeGroupId = 'computeGroupId';
	case TimeEstimate = 'timeEstimate';
	case AllowChangeDeadline = 'allowChangeDeadline';
	case AllowTimeTracking = 'allowTimeTracking';
	case MatchWorkTime = 'matchWorkTime';
	case Sorting = 'sorting';
	case SortingOrder = 'sortingOrder';
	case MessageId = 'messageId';
	case Favorite = 'favorite';
	case ComputeFavorite = 'computeFavorite';
	case TimeSpentInLogs = 'timeSpentInLogs';
	case IsPinned = 'isPinned';
	case IsPinnedInGroup = 'isPinnedInGroup';
	case ScrumItemsSort = 'scrumItemsSort';
	case ImChatId = 'imChatId';
	case Description = 'description';
	case DescriptionInBbcode = 'descriptionInBbcode';
	case DeclineReason = 'declineReason';
	case ComputeStatus = 'computeStatus';
	case Multitask = 'multitask';
	case StageId = 'stageId';
	case StagesId = 'stagesId';
	case ResponsibleSecondName = 'responsibleSecondName';
	case ResponsibleLogin = 'responsibleLogin';
	case ResponsibleWorkPosition = 'responsibleWorkPosition';
	case ResponsiblePhoto = 'responsiblePhoto';
	case Replicate = 'replicate';
	case DeadlineOrig = 'deadlineOrig';
	case CreatedByName = 'createdByName';
	case CreatedBySecondName = 'createdBySecondName';
	case CreatedByLogin = 'createdByLogin';
	case CreatedByWorkPosition = 'createdByWorkPosition';
	case CreatedByPhoto = 'createdByPhoto';
	case ChangedBy = 'changedBy';
	case StatusChangedBy = 'statusChangedBy';
	case ClosedBy = 'closedBy';
	case Guid = 'guid';
	case XmlId = 'xmlId';
	case TaskControl = 'taskControl';
	case AddInReport = 'addInReport';
	case GroupName = 'groupName';
	case ForumTopicId = 'forumTopicId';
	case ParentId = 'parentId';
	case CommentsCount = 'commentsCount';
	case ServiceCommentsCount = 'serviceCommentsCount';
	case ForumId = 'forumId';
	case SiteId = 'siteId';
	case ExchangeModified = 'exchangeModified';
	case ExchangeId = 'exchangeId';
	case OutlookVersion = 'outlookVersion';
	case ViewedDate = 'viewedDate';
	case DeadlineCounted = 'deadlineCounted';
	case ForkedByTemplateId = 'forkedByTemplateId';
	case NotViewed = 'notViewed';
	case ImChatMessageId = 'imChatMessageId';
	case ImChatChatId = 'imChatChatId';
	case ImChatAuthorId = 'imChatAuthorId';
	case DurationPlanSeconds = 'durationPlanSeconds';
	case DurationTypeAll = 'durationTypeAll';
	case DurationPlan = 'durationPlan';
	case DurationType = 'durationType';
	case StatusChangedDate = 'statusChangedDate';
	case DurationFact = 'durationFact';
	case IsMuted = 'isMuted';
	case Subordinate = 'subordinate';
	case Count = 'count';
	case NullSorting = 'nullSorting';
	case LengthDeadline = 'lengthDeadline';
	case ScenarioName = 'scenarioName';
	case SprintId = 'sprintId';
	case BacklogId = 'backlogId';
	case IsRegular = 'isRegular';
	case FlowId = 'flowId';
	case Flow = 'flow';
	case ChatId = 'chatId';
	case UfCrmTask = 'ufCrmTask';
	case FullSearchIndex = 'fullSearchIndex';
	case CommentSearchIndex = 'commentSearchIndex';
	case Tag = 'tag';
	case TagId = 'tagId';
	case Viewed = 'viewed';
	case StatusExpired = 'statusExpired';
	case StatusNew = 'statusNew';
	case Accomplice = 'accomplice';
	case Auditor = 'auditor';
	case Period = 'period';
	case Active = 'active';
	case Doer = 'doer';
	case Member = 'member';
	case DependsOn = 'dependsOn';
	case DependsOnTemplate = 'dependsOnTemplate';
	case GanttAncestorId = 'ganttAncestorId';
	case OnlyRootTasks = 'onlyRootTasks';
	case SubordinateTasks = 'subordinateTasks';
	case Overdued = 'overdued';
	case SameGroupParent = 'sameGroupParent';
	case SameGroupParentEx = 'sameGroupParentEx';
	case DepartmentId = 'departmentId';
	case CheckPermissions = 'checkPermissions';
	case ProjectNewComments = 'projectNewComments';
	case ProjectExpired = 'projectExpired';
	case Mentioned = 'mentioned';
	case WithCommentCounters = 'withCommentCounters';
	case WithNewComments = 'withNewComments';
	case WithNewCommentsForum = 'withNewCommentsForum';
	case ScrumTasks = 'scrumTasks';
	case StoryPoints = 'storyPoints';
	case Epic = 'epic';
	case Accomplices = 'accomplices';
	case Auditors = 'auditors';
	case Tags = 'tags';
	case Links = 'links';

	public static function allowedForSortList(): array
	{
		return [
			self::Id,
			self::Title,
			self::DateStart,
			self::CreatedDate,
			self::ChangedDate,
			self::ClosedDate,
			self::ActivityDate,
			self::StartDatePlan,
			self::EndDatePlan,
			self::Deadline,
			self::Status,
			self::RealStatus,
			self::StatusComplete,
			self::Priority,
			self::Mark,
			self::OriginatorName,
			self::CreatedBy,
			self::CreatedByLastName,
			self::ResponsibleName,
			self::ResponsibleId,
			self::ResponsibleLastName,
			self::GroupId,
			self::ComputeGroupId,
			self::TimeEstimate,
			self::AllowChangeDeadline,
			self::AllowTimeTracking,
			self::MatchWorkTime,
			self::Sorting,
			self::SortingOrder,
			self::MessageId,
			self::Favorite,
			self::ComputeFavorite,
			self::TimeSpentInLogs,
			self::IsPinned,
			self::IsPinnedInGroup,
			self::ScrumItemsSort,
			self::ImChatId,
		];
	}

	public static function allowedForSelectList(): array
	{
		return [
			self::Id,
			self::Title,
			self::Description,
			self::DescriptionInBbcode,
			self::DeclineReason,
			self::Priority,
			self::Status,
			self::StatusComplete,
			self::ComputeStatus,
			self::RealStatus,
			self::Multitask,
			self::StageId,
			self::StagesId,
			self::ResponsibleId,
			self::ResponsibleName,
			self::ResponsibleLastName,
			self::ResponsibleSecondName,
			self::ResponsibleLogin,
			self::ResponsibleWorkPosition,
			self::ResponsiblePhoto,
			self::DateStart,
			self::TimeEstimate,
			self::Replicate,
			self::Deadline,
			self::DeadlineOrig,
			self::StartDatePlan,
			self::EndDatePlan,
			self::CreatedBy,
			self::CreatedByName,
			self::CreatedByLastName,
			self::CreatedBySecondName,
			self::CreatedByLogin,
			self::CreatedByWorkPosition,
			self::CreatedByPhoto,
			self::CreatedDate,
			self::ChangedBy,
			self::ChangedDate,
			self::StatusChangedBy,
			self::ClosedBy,
			self::ClosedDate,
			self::ActivityDate,
			self::Guid,
			self::XmlId,
			self::Mark,
			self::AllowChangeDeadline,
			self::AllowTimeTracking,
			self::MatchWorkTime,
			self::TaskControl,
			self::AddInReport,
			self::ComputeGroupId,
			self::Group,
			self::GroupId,
			self::GroupName,
			self::ForumTopicId,
			self::ParentId,
			self::CommentsCount,
			self::ServiceCommentsCount,
			self::ForumId,
			self::MessageId,
			self::SiteId,
			self::ExchangeModified,
			self::ExchangeId,
			self::OutlookVersion,
			self::ViewedDate,
			self::DeadlineCounted,
			self::ForkedByTemplateId,
			self::NotViewed,
			self::ComputeFavorite,
			self::Sorting,
			self::ImChatId,
			self::ImChatMessageId,
			self::ImChatChatId,
			self::ImChatAuthorId,
			self::DurationPlanSeconds,
			self::DurationTypeAll,
			self::DurationPlan,
			self::DurationType,
			self::StatusChangedDate,
			self::TimeSpentInLogs,
			self::DurationFact,
			self::IsMuted,
			self::IsPinned,
			self::IsPinnedInGroup,
			self::Subordinate,
			self::Count,
			self::NullSorting,
			self::LengthDeadline,
			self::ScenarioName,
			self::SprintId,
			self::BacklogId,
			self::IsRegular,
			self::FlowId,
			self::Flow,
			self::ChatId,
			self::UfCrmTask,
			self::Accomplices,
			self::Auditors,
			self::Tags,
			self::Links,
		];
	}

	public static function allowedForFilterList(): array
	{
		return [
			self::ParentId,
			self::GroupId,
			self::StatusChangedBy,
			self::ForumTopicId,
			self::Id,
			self::Priority,
			self::CreatedBy,
			self::ResponsibleId,
			self::StageId,
			self::TimeEstimate,
			self::ForkedByTemplateId,
			self::DeadlineCounted,
			self::ChangedBy,
			self::Guid,
			self::Title,
			self::FullSearchIndex,
			self::CommentSearchIndex,
			self::Tag,
			self::TagId,
			self::Flow,
			self::FlowId,
			self::SprintId,
			self::BacklogId,
			self::RealStatus,
			self::Viewed,
			self::StatusExpired,
			self::StatusNew,
			self::Status,
			self::Mark,
			self::XmlId,
			self::SiteId,
			self::AddInReport,
			self::AllowTimeTracking,
			self::AllowChangeDeadline,
			self::MatchWorkTime,
			self::IsRegular,
			self::EndDatePlan,
			self::StartDatePlan,
			self::DateStart,
			self::Deadline,
			self::CreatedDate,
			self::ClosedDate,
			self::ChangedDate,
			self::ActivityDate,
			self::Accomplice,
			self::Auditor,
			self::Period,
			self::Active,
			self::Doer,
			self::Member,
			self::DependsOn,
			self::DependsOnTemplate,
			self::GanttAncestorId,
			self::OnlyRootTasks,
			self::SubordinateTasks,
			self::Overdued,
			self::SameGroupParent,
			self::SameGroupParentEx,
			self::DepartmentId,
			self::CheckPermissions,
			self::Favorite,
			self::Sorting,
			self::StagesId,
			self::ProjectNewComments,
			self::ProjectExpired,
			self::Mentioned,
			self::WithCommentCounters,
			self::WithNewComments,
			self::WithNewCommentsForum,
			self::IsMuted,
			self::IsPinned,
			self::IsPinnedInGroup,
			self::ScrumTasks,
			self::StoryPoints,
			self::Epic,
			self::ScenarioName,
			self::ImChatId,
			self::ImChatChatId,
		];
	}
}
