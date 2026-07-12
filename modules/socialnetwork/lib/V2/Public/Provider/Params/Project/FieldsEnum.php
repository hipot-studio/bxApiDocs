<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider\Params\Project;

enum FieldsEnum: string
{
	case Id = 'id';
	case Name = 'name';
	case ImageId = 'imageId';
	case Description = 'description';
	case Goal = 'goal';
	case OwnerId = 'ownerId';
	case DateCreate = 'dateCreate';
	case DateUpdate = 'dateUpdate';
	case DateActivity = 'dateActivity';
	case ActivityDate = 'activityDate';
	case DateRelation = 'dateRelation';
	case DateView = 'dateView';
	case Project = 'project';
	case Active = 'active';
	case Closed = 'closed';
	case Visible = 'visible';
	case Opened = 'opened';
	case ScrumMasterId = 'scrumMasterId';
	case NumberOfMembers = 'numberOfMembers';
	case ProjectDateStart = 'projectDateStart';
	case ProjectDateFinish = 'projectDateFinish';
	case Landing = 'landing';
	case Type = 'type';
	case SiteId = 'siteId';
	case SubjectId = 'subjectId';

	public function toOrmField(): string
	{
		return match ($this)
		{
			self::Id => 'ID',
			self::Name => 'NAME',
			self::ImageId => 'IMAGE_ID',
			self::Description => 'DESCRIPTION',
			self::Goal => 'GOAL',
			self::OwnerId => 'OWNER_ID',
			self::DateCreate => 'DATE_CREATE',
			self::DateUpdate => 'DATE_UPDATE',
			self::DateActivity => 'DATE_ACTIVITY',
			self::ActivityDate => 'ACTIVITY_DATE',
			self::DateRelation => 'DATE_RELATION',
			self::DateView => 'DATE_VIEW',
			self::Project => 'PROJECT',
			self::Active => 'ACTIVE',
			self::Closed => 'CLOSED',
			self::Visible => 'VISIBLE',
			self::Opened => 'OPENED',
			self::ScrumMasterId => 'SCRUM_MASTER_ID',
			self::NumberOfMembers => 'NUMBER_OF_MEMBERS',
			self::ProjectDateStart => 'PROJECT_DATE_START',
			self::ProjectDateFinish => 'PROJECT_DATE_FINISH',
			self::Landing => 'LANDING',
			self::Type => 'TYPE',
			self::SiteId => 'SITE_ID',
			self::SubjectId => 'SUBJECT_ID',
		};
	}

	public static function allowedForSelectList(): array
	{
		return [
			self::Id,
			self::Name,
			self::ImageId,
			self::Description,
			self::Goal,
			self::OwnerId,
			self::DateCreate,
			self::DateUpdate,
			self::DateActivity,
			self::ActivityDate,
			self::Project,
			self::Active,
			self::Closed,
			self::Visible,
			self::Opened,
			self::ScrumMasterId,
			self::NumberOfMembers,
			self::ProjectDateStart,
			self::ProjectDateFinish,
			self::Landing,
			self::Type,
			self::SiteId,
			self::SubjectId,
		];
	}

	public static function allowedForSortList(): array
	{
		return [
			self::Id,
			self::Name,
			self::DateCreate,
			self::DateUpdate,
			self::DateActivity,
			self::ActivityDate,
			self::DateRelation,
			self::DateView,
			self::OwnerId,
			self::NumberOfMembers,
			self::ProjectDateStart,
			self::ProjectDateFinish,
		];
	}

	public static function allowedForFilterList(): array
	{
		return [
			self::Id,
			self::Name,
			self::Description,
			self::Goal,
			self::OwnerId,
			self::DateCreate,
			self::DateUpdate,
			self::DateActivity,
			self::Project,
			self::Active,
			self::Closed,
			self::Visible,
			self::Opened,
			self::NumberOfMembers,
			self::ProjectDateStart,
			self::ProjectDateFinish,
			self::Landing,
			self::Type,
			self::SiteId,
			self::SubjectId,
		];
	}

	public static function fromOrmField(string $ormField): ?self
	{
		foreach (self::cases() as $case)
		{
			if ($case->toOrmField() === $ormField)
			{
				return $case;
			}
		}

		return null;
	}
}
