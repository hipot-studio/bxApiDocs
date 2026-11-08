<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Onboarding;

use Bitrix\Main\Data\ConnectionPool;
use CUserOptions;

/**
 * Personal mark of a spent onboarding show, kept in the user options.
 * Left open for extension so tests can replace it with a stub.
 */
class DigestSetupOnboardingMarkStorage
{
	private const OPTION_CATEGORY = 'biconnector';
	private const MARK_SPENT = 'Y';

	public function __construct(private readonly ConnectionPool $connectionPool)
	{
	}

	/**
	 * Cached read for page rendering.
	 */
	public function isSpent(string $optionName, int $userId): bool
	{
		return CUserOptions::getOption(self::OPTION_CATEGORY, $optionName, '', $userId) === self::MARK_SPENT;
	}

	/**
	 * Read past the option cache and the replicas: the claim decides on it under a lock, and a stale answer
	 * would spend the show twice.
	 */
	public function isSpentOnMaster(string $optionName, int $userId): bool
	{
		$this->connectionPool->useMasterOnly(true);

		try
		{
			$row = CUserOptions::getList([], [
				'USER_ID' => $userId,
				'CATEGORY' => self::OPTION_CATEGORY,
				'NAME' => mb_strtolower($optionName),
			])->fetch();
		}
		finally
		{
			$this->connectionPool->useMasterOnly(false);
		}

		$stored = $row ? (string)($row['VALUE'] ?? '') : '';
		if ($stored === '')
		{
			return false;
		}

		return unserialize($stored, ['allowed_classes' => false]) === self::MARK_SPENT;
	}

	public function markSpent(string $optionName, int $userId): bool
	{
		return CUserOptions::setOption(self::OPTION_CATEGORY, $optionName, self::MARK_SPENT, false, $userId) === true;
	}
}
