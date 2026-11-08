<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message;

use Bitrix\Main\Localization\Loc;

/**
 * Announces the people, departments and teams removed by one membership edit.
 */
class ProjectDepartmentMembersRemoved extends AbstractProjectMembersMessage
{
	protected function renderSentence(string $members): string
	{
		return (string)Loc::getMessage('SONET_V2_PROJECT_MEMBERS_REMOVED', ['#MEMBERS#' => $members]);
	}
}
