<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message;

use Bitrix\Main\Localization\Loc;

/**
 * System message for the project (collab) chat: a department was unlinked from the project, its members
 * removed. Project-phrased, sent from the socialnetwork side.
 */
class ProjectDepartmentMembersRemoved implements MessageDataInterface
{
	public function __construct(
		private readonly string $departmentName,
	)
	{
	}

	public function getText(): string
	{
		$message = Loc::getMessage(
			'SONET_V2_PROJECT_DEPARTMENT_MEMBERS_REMOVED',
			['#DEPARTMENT_NAME#' => $this->departmentName],
		);

		return $message ?? '';
	}

	public function getContextUserId(): int
	{
		return 0;
	}

	public function getAuthorId(): int
	{
		return 0;
	}
}
