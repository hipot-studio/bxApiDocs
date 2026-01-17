<?php

namespace Bitrix\Im\V2\Integration\AI;

use Bitrix\Im\V2\Entity\User\User;

class UserKeyConverter
{
	private int $chatId;

	public function __construct(int $chatId)
	{
		$this->chatId = $chatId;
	}

	public function getAnonymizedUserKey(int $userId): string
	{
		$user = User::getInstance($userId);
		$userFirstName = preg_replace("#\s+#", '_', trim($user->getFirstName() ?? '')) ?: "User";
		$userFirstName = trim($userFirstName);

		if ($userId === AIHelper::getCopilotBotId())
		{
			return $userFirstName;
		}

		$hash = $this->generateShortId($userId);

		return "{$userFirstName}_{$hash}";
	}

	public function getUserKey(int $userId): string
	{
		$user = User::getInstance($userId);
		$userName = preg_replace("#\s+#", '_', trim($user->getName() ?? '')) ?: 'User';
		$userName = trim($userName);

		if ($userId === AIHelper::getCopilotBotId())
		{
			return $userName;
		}

		$hash = $this->generateShortId($userId);

		return "{$userName}_{$hash}";
	}

	public function convertToUserId(string $userKey): ?int
	{
		$userId = preg_replace_callback(
			"#.*_([0-9]+)$#iu",
			function($matches) {
				return $this->extractUserId((string)$matches[1]);
			},
			$userKey
		);

		return is_numeric($userId) ? (int)$userId : null;
	}

	public function extractUserId(string $shortId): ?int
	{
		$len = strlen($shortId);
		if ($len < 2)
		{
			return null;
		}

		for ($i = 1; $i < $len; $i++)
		{
			$numStr = substr($shortId, 0, $i);
			$sumStr = substr($shortId, $i);

			if (!is_numeric($numStr) || !is_numeric($sumStr))
			{
				continue;
			}

			$sum  = 0;
			$flag = 0;
			for ($j = strlen($numStr) - 1; $j >= 0; $j--)
			{
				$digit = (int)$numStr[$j];
				$add = ($flag++ & 1) ? $digit * 2 : $digit;
				$sum += $add > 9 ? $add - 9 : $add;
			}

			if ((string)$sum === $sumStr)
			{
				$total = (int)$numStr;
				$userId = $total - $this->chatId;

				if ($userId >= 0)
				{
					return $userId;
				}
			}
		}

		return null;
	}

	private function generateShortId(int $userId): string
	{
		$number = (string)($this->chatId + $userId);
		$sum = 0;
		$flag = 0;
		for ($i = strlen($number) - 1; $i >= 0; $i--)
		{
			$add = $flag++ & 1 ? $number[$i] * 2 : $number[$i];
			$sum += $add > 9 ? $add - 9 : $add;
		}

		return $number . $sum;
	}
}
