<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Socialnetwork;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Provider\GroupProvider;

class ProjectPredicateService
{
	private bool $isAvailable;

	public function __construct()
	{
		$this->isAvailable = $this->isSocialnetworkAvailable();
	}

	public function isNewProject(int $groupId): bool
	{
		if ($groupId <= 0 || !$this->isAvailable)
		{
			return false;
		}

		return $this->loadIsNewProject($groupId);
	}

	public function preloadGroupTypes(int ...$groupIds): void
	{
		if (!$this->isAvailable)
		{
			return;
		}

		$groupIds = array_values(array_unique(array_filter(
			$groupIds,
			static fn(int $groupId): bool => $groupId > 0,
		)));
		if ($groupIds === [])
		{
			return;
		}

		$this->loadGroupTypes(...$groupIds);
	}

	protected function isSocialnetworkAvailable(): bool
	{
		return
			Loader::includeModule('socialnetwork')
			&& method_exists(GroupProvider::class, 'isCollabProject')
			&& method_exists(GroupProvider::class, 'loadGroupTypes');
	}

	protected function loadIsNewProject(int $groupId): bool
	{
		return GroupProvider::getInstance()->isCollabProject($groupId);
	}

	protected function loadGroupTypes(int ...$groupIds): void
	{
		GroupProvider::getInstance()->loadGroupTypes(...$groupIds);
	}

}
