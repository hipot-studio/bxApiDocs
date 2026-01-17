<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI;

use Bitrix\Im\V2\Entity\User\User;

final class MentionService
{
	private static ?MentionService $service = null;
	private array $userName = [];

	public static function getInstance(): self
	{
		self::$service ??= new self();

		return self::$service;
	}

	public function replaceBbMentions(string $text, int $chatId): ?string
	{
		return preg_replace_callback(
			"/\[USER=([0-9]+)?](.*?)\[\/USER]/i",
			function($matches) use ($chatId) {
				$userId = (int)$matches[1];

				return (new UserKeyConverter($chatId))->getAnonymizedUserKey($userId);
			},
			$text,
		);
	}

	public function replaceAiMentions(string $text, int $chatId): ?string
	{
		return preg_replace_callback(
			"#@?([[:alnum:]]+)\s*_\s*([0-9]+)#iu",
			function($matches) use ($chatId) {
				$userId = (new UserKeyConverter($chatId))->extractUserId((string)$matches[2]);
				if (!isset($userId))
				{
					return $matches[0];
				}

				return $this->buildBbMention($userId);
			},
			$text,
		);
	}

	private function getUserName(int $userId): string
	{
		if (!isset($this->userName[$userId]))
		{
			$user = User::getInstance($userId);
			$userName = $user->getName();
			$this->userName[$userId] = trim($userName);
		}

		return $this->userName[$userId];
	}

	private function buildBbMention(int $userId): string
	{
		$userName = $this->getUserName($userId);

		return "[user={$userId}]{$userName}[/user]";
	}
}
