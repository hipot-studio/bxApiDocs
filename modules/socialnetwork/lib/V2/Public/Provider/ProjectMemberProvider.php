<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider;

use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Service\ProjectService;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\MemberFilterType;

class ProjectMemberProvider
{
	private ProjectService $projectService;

	public function __construct()
	{
		$this->projectService = Container::getInstance()->getProjectService();
	}

	/**
	 * @return array<int, array{ID: int, PHOTO: string, HREF: string, FORMATTED_NAME: string, ROLE: string}>
	 */
	public function getPagedMembers(
		int $projectId,
		MemberFilterType $type = MemberFilterType::All,
		int $page = 1,
		int $pageSize = 10,
	): array
	{
		return $this->projectService->getMembers(
			projectId: $projectId,
			type: $type->value,
			page: $page,
			pageSize: $pageSize,
		);
	}
}
