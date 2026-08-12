<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message;

use Bitrix\Main\Localization\Loc;

/**
 * System message for the project (collab) chat: a department's members joined the project. Project-phrased
 * (the "Project" wording lives on the socialnetwork side); im's chat-phrased finish message is suppressed
 * for CollabChat.
 */
class ProjectDepartmentMembersAdded implements MessageDataInterface
{
	public function __construct(
		private readonly string $departmentName,
	)
	{
	}

	public function getText(): string
	{
		$message = Loc::getMessage(
			'SONET_V2_PROJECT_DEPARTMENT_MEMBERS_ADDED',
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
