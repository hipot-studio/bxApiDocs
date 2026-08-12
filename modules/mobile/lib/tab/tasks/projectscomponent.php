<?php

namespace Bitrix\Mobile\Tab\Tasks;

use Bitrix\MobileApp\Janative\Manager;

final class ProjectsComponent
{
	public const LEGACY_COMPONENT = 'tasks:tasks.project.list';
	public const LEGACY_COMPONENT_CODE = 'tasks.project.list';
	public const V2_COMPONENT = 'tasks:tasks.project.list.v2';
	public const V2_COMPONENT_CODE = 'tasks.project.list.v2';

	public static function getListComponent(): array
	{
		if (self::isV2Enabled())
		{
			$scriptPath = Manager::getComponentPath(self::V2_COMPONENT);
			if ($scriptPath !== '')
			{
				return [
					'code' => self::V2_COMPONENT_CODE,
					'scriptPath' => $scriptPath,
				];
			}
		}

		return [
			'code' => self::LEGACY_COMPONENT_CODE,
			'scriptPath' => Manager::getComponentPath(self::LEGACY_COMPONENT),
		];
	}

	public static function getRootWidget(string $componentCode, ?string $title = null): array
	{
		if ($componentCode === self::V2_COMPONENT_CODE)
		{
			return self::getV2RootWidget($title);
		}

		return self::getLegacyRootWidget($title);
	}

	private static function getV2RootWidget(?string $title = null): array
	{
		$settings = [
			'objectName' => 'layout',
			'useLargeTitleMode' => true,
		];

		if ($title !== null)
		{
			$settings['title'] = $title;
		}

		return [
			'name' => 'layout',
			'settings' => $settings,
		];
	}

	private static function getLegacyRootWidget(?string $title = null): array
	{
		$settings = [
			'objectName' => 'list',
			'useSearch' => true,
			'useLargeTitleMode' => true,
			'emptyListMode' => true,
		];

		if ($title !== null)
		{
			$settings['title'] = $title;
		}

		return [
			'name' => 'tasks.list',
			'settings' => $settings,
		];
	}

	private static function isV2Enabled(): bool
	{
		return class_exists(\Bitrix\Socialnetwork\V2\Feature::class)
			&& \Bitrix\Socialnetwork\V2\Feature::isNewProjectsOn()
		;
	}
}
