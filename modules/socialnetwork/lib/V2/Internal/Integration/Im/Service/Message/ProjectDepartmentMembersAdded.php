<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message;

use Bitrix\Main\Localization\Loc;

/**
 * Announces everything one membership edit added to the project: people, departments and teams in one list.
 */
class ProjectDepartmentMembersAdded extends AbstractProjectMembersMessage
{
	protected function renderSentence(string $members): string
	{
		return (string)Loc::getMessage('SONET_V2_PROJECT_MEMBERS_ADDED', ['#MEMBERS#' => $members]);
	}
}
