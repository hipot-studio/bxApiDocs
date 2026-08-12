<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;

/**
 * Topic moderation for AI-site editing (ChangeAiSite / ChangeAiSiteSelectedElement).
 *
 * Overrides only the moderated input source — the edit instruction.
 * Prompt, verdict schema, branching, option gate and fail-closed are inherited.
 */
class RequestModerateChangeAiSiteTopic extends RequestModerateAiSiteTopic
{
	protected function resolveModerationInput(): string
	{
		return $this->encodeInputLines(ChangeAiSiteState::resolveInputLines($this->generation));
	}
}
