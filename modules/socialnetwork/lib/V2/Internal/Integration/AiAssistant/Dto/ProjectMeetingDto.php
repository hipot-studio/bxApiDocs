<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Dto;

use Bitrix\Call\DTO\FollowUp\Outcome\OverviewDto;
use Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Dto\GroupMeetingDto;

/**
 * A finished project meeting with an optional call overview.
 */
class ProjectMeetingDto
{
	public function __construct(
		public readonly GroupMeetingDto $meeting,
		public readonly ?OverviewDto $overview = null,
	)
	{
	}
}
