<?php

namespace Bitrix\Bizproc\Internal\Integration\ImBot\Service;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Main\Loader;

class MentionService
{
	public function replaceBbMentions(string $text): string
	{
		return preg_replace_callback(
			"/\[USER=([0-9]+)?](.*?)\[\/USER]/i",
			function($matches)
			{
				$userId = (int)$matches[1];

				return $this->buildAIMention($userId);
			},
			$text,
		);
	}

	private function buildAiMention(int $userId): string
	{
		$shortUserName = $this->buildShortUserName($userId);

		return "[USER={$userId}]{$shortUserName}[/USER]";
	}

	private function buildShortUserName(int $userId): string
	{
		$defaultUserName = 'User';
		if (empty($userId) || !Loader::includeModule('im'))
		{
			return $defaultUserName;
		}

		$user = User::getInstance($userId);
		$userName = trim($user->getFirstName() ?? '');
		if (empty($userName))
		{
			return $defaultUserName;
		}

		$userName = trim(preg_replace('#\s+#', '_', $userName));

		return $userName ?: $defaultUserName;
	}
}