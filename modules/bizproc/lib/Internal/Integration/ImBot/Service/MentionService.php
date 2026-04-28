<?php

namespace Bitrix\Bizproc\Internal\Integration\ImBot\Service;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class MentionService
{
	public function replaceBbMentions(string $text, int $salt = 0): ?string
	{
		return preg_replace_callback(
			'~\[USER=([0-9]+)?\](.*?)\[\\\\?\/USER\]~i',
			function($matches) use ($salt) {
				$userId = (int)$matches[1];

				return ServiceLocator::getInstance()
					->get(UserPseudonymizer::class)
					->getPseudonymizedUserMention($userId, $salt)
				;
			},
			$text,
		);
	}

	public function restoreMentions(string $text, int $salt = 0): ?string
	{
		return preg_replace_callback(
			'~\[USER=([0-9]+)\](.*?)\[\\\\?\/USER\]~i',
			function($matches) use ($salt) {
				$shortId = $matches[1];

				$userId = ServiceLocator::getInstance()
					->get(UserPseudonymizer::class)
					->extractUserId((string)$shortId, $salt)
				;
				if (!isset($userId))
				{
					return $matches[0];
				}

				return $this->buildBbMention($userId);
			},
			$text,
		);
	}

	private function buildBbMention(int $userId): string
	{
		if (!Loader::includeModule('im'))
		{
			return 'User';
		}

		$userName = $this->getUserName($userId);

		return "[USER={$userId}]{$userName}[/USER]";
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
}
