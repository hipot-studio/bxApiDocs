<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List;

enum Field: string
{
	case Id = 'ID';
	case Title = 'TITLE';
	case Description = 'DESCRIPTION';
	case DescriptionInBbcode = 'DESCRIPTION_IN_BBCODE';
	case Priority = 'PRIORITY';
	case Status = 'STATUS';
	case ResponsibleId = 'RESPONSIBLE_ID';
	case Deadline = 'DEADLINE_AFTER';
	case StartDatePlan = 'START_DATE_PLAN_AFTER';
	case EndDatePlan = 'END_DATE_PLAN_AFTER';
	case TimeEstimate = 'TIME_ESTIMATE';
	case Replicate = 'REPLICATE';
	case CreatedBy = 'CREATED_BY';
	case XmlId = 'XML_ID';
	case AllowChangeDeadline = 'ALLOW_CHANGE_DEADLINE';
	case AllowTimeTracking = 'ALLOW_TIME_TRACKING';
	case TaskControl = 'TASK_CONTROL';
	case AddInReport = 'ADD_IN_REPORT';
	case GroupId = 'GROUP_ID';
	case ParentId = 'PARENT_ID';
	case Multitask = 'MULTITASK';
	case SiteId = 'SITE_ID';
	case Files = 'FILES';
	case TagList = 'TAG_LIST';
	case DependsOn = 'DEPENDS_ON';
	case MatchWorkTime = 'MATCH_WORK_TIME';
	case TaskId = 'TASK_ID';
	case TParamType = 'TPARAM_TYPE';
	case TParamReplicationCount = 'TPARAM_REPLICATION_COUNT';
	case ReplicateParams = 'REPLICATE_PARAMS';
	case CreatedByName = 'CREATOR.NAME';
	case CreatedByLastName = 'CREATOR.LAST_NAME';
	case CreatedBySecondName = 'CREATOR.SECOND_NAME';
	case CreatedByLogin = 'CREATOR.LOGIN';
	case CreatedByWorkPosition = 'CREATOR.WORK_POSITION';
	case CreatedByPhoto = 'CREATOR.PERSONAL_PHOTO';
	case ResponsibleName = 'RESPONSIBLE.NAME';
	case ResponsibleLastName = 'RESPONSIBLE.LAST_NAME';
	case ResponsibleSecondName = 'RESPONSIBLE.SECOND_NAME';
	case ResponsibleLogin = 'RESPONSIBLE.LOGIN';
	case ResponsibleWorkPosition = 'RESPONSIBLE.WORK_POSITION';
	case ResponsiblePhoto = 'RESPONSIBLE.PERSONAL_PHOTO';
	case Scenario = 'SCENARIO.SCENARIO';
	case BaseTemplateId = 'BASE_TEMPLATE_ID';
	case TemplateChildrenCount = 'TEMPLATE_CHILDREN_COUNT';
	case Zombie = 'ZOMBIE';
	case SearchIndex = 'SEARCH_INDEX';
	case AccessUserId = 'ACCESS_USER_ID';
	case SubTemplates = 'SUB_TEMPLATES';
	case Members = 'MEMBERS';
	case UserFields = 'UF_*';
	case StageId = 'STAGE_ID';
}