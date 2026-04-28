<?php

namespace Bitrix\Bizproc\Public\Integration\AI\Service;

use Bitrix\Bizproc\Internal\Integration\ImBot\Service\MentionService;

class ObfuscationService
{
	public function __construct(
		private readonly MentionService $mentionService,
	) {}

	public function prepareTextForSending(string $content, int $salt = 0): string
	{
		return $this->mentionService->replaceBbMentions($content, $salt);
	}

	public function restoreTextAfterReceiving(string $content, int $salt = 0): string
	{
		return $this->mentionService->restoreMentions($content, $salt);
	}
}
