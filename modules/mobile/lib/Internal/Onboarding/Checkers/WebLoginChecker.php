<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Intranet\Service\UserService;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\Storage\UserOptionReader;

/**
 * "Web" step: the user has logged in from a PC/desktop at least once. The web-auth timestamp
 * is recorded only on web/desktop login, so its mere presence is the signal - $sinceDate is
 * not applied here.
 *
 * The answer is a user option rather than a fact of a table, and it is read for a whole
 * selection the same way the campaign state and the last visits of the same users already
 * are - through UserOptionReader, in chunks bounded by the selection. The published reader of
 * intranet answers one user at a time and CUserOptions has no filter by a list of users, so a
 * selection asked through either costs a query per user or a read of every record of the
 * option - and the latter grows with the number of people who ever logged in from the web,
 * which is not a number this campaign has any relation to.
 *
 * A user outside the announced selection keeps the published route: the option belongs to
 * intranet, and UserService is where its meaning lives.
 */
class WebLoginChecker implements BulkStepChecker
{
	private const OPTION_CATEGORY = 'intranet';
	private const OPTION_NAME = 'lastWebAuthorizeTime';

	/** @var array<int, true> */
	private array $announcedUsers = [];

	/** @var array<int, bool>|null */
	private ?array $completedUsers = null;

	public function preload(array $userIds, DateTime $sinceDate): void
	{
		$userIds = array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0);

		$this->announcedUsers = array_fill_keys($userIds, true);
		$this->completedUsers = null;
	}

	public function isCompleted(int $userId, DateTime $sinceDate): bool
	{
		if ($userId <= 0 || !Loader::includeModule('intranet'))
		{
			return false;
		}

		if (isset($this->announcedUsers[$userId]))
		{
			$this->completedUsers ??= $this->readCompletedUsers();

			return $this->completedUsers[$userId];
		}

		return $this->getLastWebAuthTimestamp($userId) !== null;
	}

	/**
	 * Completion of the whole announced selection, read on demand. Every announced user is
	 * present in the answer, with `false` for those the option has no moment for.
	 *
	 * @return array<int, bool>
	 */
	private function readCompletedUsers(): array
	{
		$timestamps = $this->readWebAuthTimestamps(array_keys($this->announcedUsers));

		$completed = [];
		foreach ($this->announcedUsers as $userId => $_)
		{
			$completed[$userId] = ($timestamps[$userId] ?? 0) > 0;
		}

		return $completed;
	}

	/**
	 * The moment of the last web login of each of these users, absent for those without one.
	 *
	 * @param int[] $userIds
	 * @return array<int, int>
	 */
	protected function readWebAuthTimestamps(array $userIds): array
	{
		$timestamps = [];

		foreach (UserOptionReader::read($userIds, self::OPTION_CATEGORY, self::OPTION_NAME) as $userId => $value)
		{
			$timestamps[$userId] = (int)unserialize($value, ['allowed_classes' => false]);
		}

		return $timestamps;
	}

	protected function getLastWebAuthTimestamp(int $userId): ?int
	{
		return (new UserService())->getLastAuthFromWebTimestamp($userId);
	}
}
