<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Service;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Socialnetwork\Helper\Workgroup\Access;
use Bitrix\Socialnetwork\Helper\User;
use Bitrix\Socialnetwork\Permission\GroupDictionary;
use Bitrix\Socialnetwork\Permission\User\UserModel;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\WorkgroupSiteTable;
use Bitrix\Socialnetwork\WorkgroupTable;

class TemplateProjectUsageService
{
	/**
	 * @param array<int, list<int>> $projectIdsByTemplate
	 * @return array<int, list<int>>
	 */
	public function getUserIdsByTemplate(
		array $projectIdsByTemplate,
		int $viewerId,
		bool $viewerIsCurrentUser = false,
	): array
	{
		if ($projectIdsByTemplate === [] || $viewerId <= 0 || !$this->isAvailable())
		{
			return [];
		}

		$projectIds = [];
		foreach ($projectIdsByTemplate as $templateProjectIds)
		{
			foreach ($templateProjectIds as $projectId)
			{
				if (is_int($projectId) && $projectId > 0)
				{
					$projectIds[$projectId] = $projectId;
				}
			}
		}

		$allowedProjectIds = $this->filterViewableProjectIds(
			array_values($projectIds),
			$viewerId,
			$viewerIsCurrentUser,
		);

		if ($allowedProjectIds === [])
		{
			return [];
		}

		$membersByProject = [];
		foreach ($this->fetchMembers($allowedProjectIds) as $member)
		{
			$membersByProject[(int)$member['GROUP_ID']][] = (int)$member['USER_ID'];
		}

		$userIdsByTemplate = [];
		foreach ($projectIdsByTemplate as $templateId => $templateProjectIds)
		{
			$userIds = [];
			foreach ($templateProjectIds as $projectId)
			{
				foreach ($membersByProject[$projectId] ?? [] as $userId)
				{
					$userIds[$userId] = $userId;
				}
			}
			$userIdsByTemplate[$templateId] = array_values($userIds);
		}

		return $userIdsByTemplate;
	}

	protected function isAvailable(): bool
	{
		return Loader::includeModule('socialnetwork');
	}

	protected function filterViewableProjectIds(
		array $projectIds,
		int $viewerId,
		bool $viewerIsCurrentUser = false,
	): array
	{
		$projectsById = [];
		$visibleProjectIds = [];
		foreach ($this->fetchProjects($projectIds) as $project)
		{
			$projectId = (int)$project['ID'];
			$isVisible = $project['VISIBLE'] === 'Y';
			$projectsById[$projectId] = $isVisible;
			if ($isVisible)
			{
				$visibleProjectIds[] = $projectId;
			}
		}

		if ($projectsById === [])
		{
			return [];
		}

		if ($this->isModuleAdmin($viewerId, $viewerIsCurrentUser))
		{
			return array_values(array_filter(
				$projectIds,
				static fn(int $projectId): bool => isset($projectsById[$projectId]),
			));
		}

		$memberProjectIds = array_fill_keys($this->fetchViewerMemberProjectIds($projectIds, $viewerId), true);
		$isExtranetUser = false;
		$extranetProjectIds = [];
		if ($visibleProjectIds !== [])
		{
			$isExtranetUser = $this->isExtranetUser($this->getVisibilityUserId($viewerId));
			if ($isExtranetUser)
			{
				$extranetProjectIds = array_fill_keys($this->fetchExtranetProjectIds($visibleProjectIds), true);
			}
		}

		$viewableProjectIds = [];
		foreach ($projectIds as $projectId)
		{
			if (!isset($projectsById[$projectId]))
			{
				continue;
			}

			if (
				isset($memberProjectIds[$projectId])
				|| (
					$projectsById[$projectId]
					&& (!$isExtranetUser || isset($extranetProjectIds[$projectId]))
				)
			)
			{
				$viewableProjectIds[] = $projectId;
			}
		}

		return $viewableProjectIds;
	}

	protected function fetchProjects(array $projectIds): array
	{
		return WorkgroupTable::query()
			->setSelect(['ID', 'VISIBLE'])
			->whereIn('ID', $projectIds)
			->fetchAll()
		;
	}

	protected function fetchViewerMemberProjectIds(array $projectIds, int $viewerId): array
	{
		return array_column(
			UserToGroupTable::query()
				->setSelect(['GROUP_ID'])
				->whereIn('GROUP_ID', $projectIds)
				->where('USER_ID', $viewerId)
				->whereIn('ROLE', UserToGroupTable::getRolesMember())
				->fetchAll(),
			'GROUP_ID',
		);
	}

	protected function fetchExtranetProjectIds(array $projectIds): array
	{
		if (!Loader::includeModule('extranet'))
		{
			return [];
		}

		$siteId = (string)\CExtranet::GetExtranetSiteID();
		if ($siteId === '')
		{
			return [];
		}

		return array_column(
			WorkgroupSiteTable::query()
				->setSelect(['GROUP_ID'])
				->whereIn('GROUP_ID', $projectIds)
				->where('SITE_ID', $siteId)
				->fetchAll(),
			'GROUP_ID',
		);
	}

	protected function isExtranetUser(int $viewerId): bool
	{
		return UserModel::createFromId($viewerId)->isExtranet();
	}

	protected function getVisibilityUserId(int $viewerId): int
	{
		return Access::isRuleEnabled(GroupDictionary::VIEW) ? $viewerId : User::getCurrentUserId();
	}

	protected function isModuleAdmin(int $viewerId, bool $viewerIsCurrentUser = false): bool
	{
		if (!Access::isRuleEnabled(GroupDictionary::VIEW))
		{
			return \CSocNetUser::isCurrentUserModuleAdmin(SITE_ID, true);
		}

		if ($viewerIsCurrentUser)
		{
			return \CSocNetUser::isCurrentUserModuleAdmin(SITE_ID, true);
		}

		return \CSocNetUser::IsUserModuleAdmin($viewerId);
	}

	protected function fetchMembers(array $projectIds): array
	{
		return UserToGroupTable::query()
			->setSelect(['GROUP_ID', 'USER_ID'])
			->whereIn('GROUP_ID', $projectIds)
			->whereIn('ROLE', UserToGroupTable::getRolesMember())
			->where('USER.ACTIVE', 'Y')
			->where(
				Query::filter()
					->logic('or')
					->whereNull('USER.EXTERNAL_AUTH_ID')
					->whereNot('USER.EXTERNAL_AUTH_ID', 'bot')
			)
			->fetchAll()
		;
	}
}
