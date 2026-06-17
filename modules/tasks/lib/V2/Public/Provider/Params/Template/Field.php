<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Template;

use Bitrix\Tasks\V2\Internal\Repository\Template\List;

enum Field: string
{
	case Id = 'id';
	case Title = 'title';
	case Description = 'description';
	case DescriptionInBbcode = 'descriptionInBbcode';
	case ResponsibleLastName = 'responsibleLastName';
	case Priority = 'priority';
	case Status = 'status';
	case ResponsibleId = 'responsibleId';
	case DeadlineAfter = 'deadlineAfter';
	case StartDatePlanAfter = 'startDatePlanAfter';
	case EndDatePlanAfter = 'endDatePlanAfter';
	case Replicate = 'replicate';
	case CreatedBy = 'createdBy';
	case XmlId = 'xmlId';
	case AllowChangeDeadline = 'allowChangeDeadline';
	case AllowTimeTracking = 'allowTimeTracking';
	case TaskControl = 'taskControl';
	case AddInReport = 'addInReport';
	case GroupId = 'groupId';
	case ParentId = 'parentId';
	case Multitask = 'multitask';
	case SiteId = 'siteId';
	case Accomplices = 'accomplices';
	case Auditors = 'auditors';
	case ResponsibleCollection = 'ResponsibleCollection';
	case Files = 'files';
	case Tags = 'tags';
	case DependsOn = 'dependsOn';
	case MatchWorkTime = 'matchWorkTime';
	case TaskId = 'taskId';
	case TParamType = 'tParamType';
	case TParamReplicationCount = 'tParamReplicationCount';
	case ReplicateParams = 'replicateParams';
	case Creator = 'creator';
	case Scenario = 'scenario';
	case BaseTemplateId = 'baseTemplateId';
	case TemplateChildrenCount = 'templateChildrenCount';
	case Zombie = 'zombie';
	case SearchIndex = 'searchIndex';
	case AccessUserId = 'accessUserId';
	case SubTemplates = 'subTemplates';
	case EstimatedTime = 'estimatedTime';
	case UserFields = 'userFields';
	case StageId = 'stageId';

	public const SELECTABLE = [
		self::Id,
		self::Title,
		self::Description,
		self::DescriptionInBbcode,
		self::Priority,
		self::Status,
		self::DeadlineAfter,
		self::StartDatePlanAfter,
		self::EndDatePlanAfter,
		self::Replicate,
		self::XmlId,
		self::AllowChangeDeadline,
		self::AllowTimeTracking,
		self::TaskControl,
		self::AddInReport,
		self::GroupId,
		self::ParentId,
		self::Multitask,
		self::SiteId,
		self::Creator,
		self::Accomplices,
		self::Auditors,
		self::ResponsibleCollection,
		self::Files,
		self::Tags,
		self::DependsOn,
		self::MatchWorkTime,
		self::TaskId,
		self::TParamType,
		self::TParamReplicationCount,
		self::ReplicateParams,
		self::Scenario,
		self::BaseTemplateId,
		self::TemplateChildrenCount,
		self::Zombie,
		self::SubTemplates,
		self::EstimatedTime,
		self::UserFields,
		self::StageId,
	];

	public const SORTABLE = [
		self::Id,
		self::GroupId,
		self::Title,
		self::ResponsibleLastName,
		self::DependsOn,
		self::TParamType,
		self::TemplateChildrenCount,
		self::BaseTemplateId,
		self::TaskId,
		self::StageId,
	];

	public const FILTERABLE = [
		self::Zombie,
		self::Scenario,
		self::CreatedBy,
		self::TaskId,
		self::GroupId,
		self::TParamType,
		self::Id,
		self::ResponsibleId,
		self::Tags,
		self::Title,
		self::XmlId,
		self::Replicate,
		self::Priority,
		self::SearchIndex,
		self::BaseTemplateId,
		self::AccessUserId,
		self::StageId,
	];

	public static function allowedForSelect(Field $field): bool
	{
		return in_array($field, self::SELECTABLE, true);
	}

	public static function allowedForSort(Field $field): bool
	{
		return in_array($field, self::SORTABLE, true);
	}

	public static function allowedForFilter(Field $field): bool
	{
		return in_array($field, self::FILTERABLE, true);
	}

	public static function getDefaultMapToRepositoryField(): array
	{
		return [
			self::DeadlineAfter->value => List\Field::Deadline,
			self::StartDatePlanAfter->value => List\Field::StartDatePlan,
			self::EndDatePlanAfter->value => List\Field::EndDatePlan,
			self::Replicate->value => List\Field::Replicate,
			self::CreatedBy->value => List\Field::CreatedBy,
			self::Creator->value => List\Field::Members,
			self::XmlId->value => List\Field::XmlId,
			self::AllowChangeDeadline->value => List\Field::AllowChangeDeadline,
			self::AllowTimeTracking->value => List\Field::AllowTimeTracking,
			self::TaskControl->value => List\Field::TaskControl,
			self::AddInReport->value => List\Field::AddInReport,
			self::GroupId->value => List\Field::GroupId,
			self::ParentId->value => List\Field::ParentId,
			self::Multitask->value => List\Field::Multitask,
			self::SiteId->value => List\Field::SiteId,
			self::Accomplices->value => List\Field::Members,
			self::Auditors->value => List\Field::Members,
			self::ResponsibleCollection->value => List\Field::Members,
			self::Files->value => List\Field::Files,
			self::Tags->value => List\Field::TagList,
			self::DependsOn->value => List\Field::DependsOn,
			self::MatchWorkTime->value => List\Field::MatchWorkTime,
			self::TaskId->value => List\Field::TaskId,
			self::TParamType->value => List\Field::TParamType,
			self::TParamReplicationCount->value => List\Field::TParamReplicationCount,
			self::ReplicateParams->value => List\Field::ReplicateParams,
			self::Scenario->value => List\Field::Scenario,
			self::BaseTemplateId->value => List\Field::BaseTemplateId,
			self::TemplateChildrenCount->value => List\Field::TemplateChildrenCount,
			self::Zombie->value => List\Field::Zombie,
			self::SubTemplates->value => List\Field::SubTemplates,
			self::EstimatedTime->value => List\Field::TimeEstimate,
			self::ResponsibleId->value => List\Field::ResponsibleId,
			self::ResponsibleLastName->value => List\Field::ResponsibleLastName,
			self::Id->value => List\Field::Id,
			self::Title->value => List\Field::Title,
			self::Priority->value => List\Field::Priority,
			self::SearchIndex->value => List\Field::SearchIndex,
			self::AccessUserId->value => List\Field::AccessUserId,
			self::UserFields->value => List\Field::UserFields,
			self::Description->value => List\Field::Description,
			self::DescriptionInBbcode->value => List\Field::DescriptionInBbcode,
			self::Status->value => List\Field::Status,
			self::StageId->value => List\Field::StageId,
		];
	}
}