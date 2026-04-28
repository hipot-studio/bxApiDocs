<?php

namespace Bitrix\Bizproc\Internal\Integration\ImBot\Service;

use Bitrix\Main\Loader;
use Bitrix\Im\V2\Entity\User\User;

class UserPseudonymizer
{
	public function getPseudonymizedUserMention(int $userId, int $salt): string
	{
		if (!Loader::includeModule('im'))
		{
			return 'User';
		}

		$user = User::getInstance($userId);
		$userFirstName = preg_replace("#\s+#", '', trim($user->getFirstName() ?? '')) ?: "User";
		$userFirstName = trim($userFirstName);

		$hash = $this->generateShortId($userId, $salt);

		return "[USER={$hash}]{$userFirstName}[/USER]";
	}

	public function extractUserId(string $shortId, int $salt): ?int
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
				$userId = $total - $salt;

				if ($userId >= 0)
				{
					return $userId;
				}
			}
		}

		return null;
	}

	private function generateShortId(int $userId, int $salt): string
	{
		$number = (string)($salt + $userId);
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
