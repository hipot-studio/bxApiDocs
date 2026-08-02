<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

final class SyncAiAgentNodesAgent extends SyncSystemNodesAgent
{
	protected static function getSectionId(): string
	{
		return 'AI_AGENT';
	}
}
