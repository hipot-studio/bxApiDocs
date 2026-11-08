<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Socialnetwork\EO_Workgroup;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\ProjectVisibility;

/**
 * A project row read for the view info block, together with its tags.
 *
 * The caller checks the view permission against the very row it has read, and maps that same
 * row into the read model afterwards. Tags come from a query of their own - joining them
 * onto the row would return it once per tag. See ProjectRepository::findViewInfoRow().
 */
class ProjectViewInfoRow
{
	/**
	 * @param string[] $tags
	 */
	public function __construct(
		public readonly EO_Workgroup $group,
		public readonly array $tags,
	)
	{
	}

	/**
	 * Hands the permission check what it needs without handing it the ORM row: both values come
	 * from this row, so no caller can pair one project's identifier with another's flag.
	 */
	public function visibility(): ProjectVisibility
	{
		return new ProjectVisibility(
			projectId: (int)$this->group->getId(),
			isVisible: (bool)$this->group->getVisible(),
		);
	}
}
