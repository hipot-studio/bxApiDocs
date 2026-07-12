<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Helper\Path;

enum FeatureDictionary: string
{
	case Tasks = 'tasks';
	case Chat = 'chat';
	case Calendar = 'calendar';
	case Files = 'files';
	case Photo = 'photo';
	case Marketplace = 'marketplace';
	case LandingKnowledge = 'landing_knowledge';
	case Flows = 'flows';
	case Blog = 'blog';
	case Forum = 'forum';
	case GroupLists = 'group_lists';
	case Wiki = 'wiki';

	public static function getBaseFeatureIds(): array
	{
		return [
			...self::getRegularFeatureIds(),
			...self::getSpecialFeatureIds(),
		];
	}

	public static function getRegularFeatureIds(): array
	{
		return array_map(
			static fn(self $feature): string => $feature->value,
			self::getRegularFeatures(),
		);
	}

	public static function getSpecialFeatureIds(): array
	{
		return array_map(
			static fn(self $feature): string => $feature->value,
			self::getSpecialFeatures(),
		);
	}

	public static function isSupported(string $featureId): bool
	{
		return in_array($featureId, self::getBaseFeatureIds(), true) || self::isPlacementFeature($featureId);
	}

	public static function isPlacementFeature(string $featureId): bool
	{
		return str_starts_with($featureId, self::getPlacementPrefix());
	}

	public static function getPlacementPrefix(): string
	{
		return 'placement_';
	}

	public static function getPlacementId(string $featureId): string
	{
		if (!self::isPlacementFeature($featureId))
		{
			return '';
		}

		return substr($featureId, strlen(self::getPlacementPrefix()));
	}

	public static function getDefaultNameById(string $featureId): string
	{
		if (self::isPlacementFeature($featureId))
		{
			return Loc::getMessage('SOCIALNETWORK_V2_PROJECT_FEATURE_APP') ?? $featureId;
		}

		return self::tryFrom($featureId)?->getDefaultName() ?? $featureId;
	}

	public function getDefaultName(): string
	{
		return Loc::getMessage($this->getLocMessageCode()) ?? $this->value;
	}

	public static function getUrlTemplateById(string $featureId): ?string
	{
		if (self::isPlacementFeature($featureId))
		{
			return Path::get('group_path_template') . 'app/#placement_id#/';
		}

		return self::tryFrom($featureId)?->getUrlTemplate();
	}

	public function getUrlTemplate(): ?string
	{
		$pathKey = $this->getPathKey();
		if ($pathKey === null)
		{
			return null;
		}

		return Path::get($pathKey);
	}

	private static function getRegularFeatures(): array
	{
		return [
			self::Tasks,
			self::Chat,
			self::Calendar,
			self::Files,
			self::Photo,
			self::Marketplace,
			self::LandingKnowledge,
			self::Blog,
			self::Forum,
			self::GroupLists,
			self::Wiki,
		];
	}

	private static function getSpecialFeatures(): array
	{
		return [self::Flows];
	}

	private function getLocMessageCode(): string
	{
		return match ($this)
		{
			self::Tasks => 'SOCIALNETWORK_V2_PROJECT_FEATURE_TASKS',
			self::Chat => 'SOCIALNETWORK_V2_PROJECT_FEATURE_CHAT',
			self::Calendar => 'SOCIALNETWORK_V2_PROJECT_FEATURE_CALENDAR',
			self::Files => 'SOCIALNETWORK_V2_PROJECT_FEATURE_FILES',
			self::Photo => 'SOCIALNETWORK_V2_PROJECT_FEATURE_PHOTO',
			self::Marketplace => 'SOCIALNETWORK_V2_PROJECT_FEATURE_MARKETPLACE',
			self::LandingKnowledge => 'SOCIALNETWORK_V2_PROJECT_FEATURE_LANDING_KNOWLEDGE',
			self::Flows => 'SOCIALNETWORK_V2_PROJECT_FEATURE_FLOWS',
			self::Blog => 'SOCIALNETWORK_V2_PROJECT_FEATURE_BLOG',
			self::Forum => 'SOCIALNETWORK_V2_PROJECT_FEATURE_FORUM',
			self::GroupLists => 'SOCIALNETWORK_V2_PROJECT_FEATURE_GROUP_LISTS',
			self::Wiki => 'SOCIALNETWORK_V2_PROJECT_FEATURE_WIKI',
		};
	}

	private function getPathKey(): ?string
	{
		return match ($this)
		{
			self::Tasks => 'group_tasks_path_template',
			self::Calendar => 'group_calendar_path_template',
			self::Files => 'group_files_path_template',
			self::Photo => 'group_photo_path_template',
			self::Marketplace => 'group_marketplace_path_template',
			self::Blog => 'group_blog_path_template',
			self::Forum => 'group_forum_path_template',
			self::GroupLists => 'group_lists_path_template',
			self::Wiki => 'group_wiki_path_template',
			self::Chat,
			self::LandingKnowledge,
			self::Flows => null,
		};
	}
}
