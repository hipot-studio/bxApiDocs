<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Service\Favorite;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Type\Date;

/**
 * When to show the onboarding hint of the favorites: from the third day the user enters Disk on, once the user has
 * a live file or folder of their own in their own drive, until the hint is completed. The state is personal and
 * kept in the user option of disk.
 */
final class FavoriteOnboardingService
{
	private const OPTION_NAME = 'favorites_onboarding';
	private const ENTRIES_TO_SHOW = 3;
	private const DATE_FORMAT = 'Y-m-d';

	/**
	 * Counts at most one entry per date.
	 */
	public function registerEntry(int $userId, Date $today): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$state = $this->read($userId);
		$day = $today->format(self::DATE_FORMAT);
		if ($state['completed'] || $state['lastEntryDate'] === $day)
		{
			return;
		}

		$state['entries']++;
		$state['lastEntryDate'] = $day;
		$this->write($userId, $state);
	}

	public function shouldShow(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$state = $this->read($userId);
		if ($state['completed'] || $state['entries'] < self::ENTRIES_TO_SHOW)
		{
			return false;
		}

		return $this->hasOwnActiveObject($userId);
	}

	public function complete(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$state = $this->read($userId);
		if ($state['completed'])
		{
			return;
		}

		$state['completed'] = true;
		$this->write($userId, $state);
	}

	private function hasOwnActiveObject(int $userId): bool
	{
		$storage = Driver::getInstance()->getStorageByUserId($userId);
		if ($storage === null)
		{
			return false;
		}

		$row = ObjectTable::query()
			->setSelect(['ID'])
			->where('STORAGE_ID', (int)$storage->getId())
			->where('CREATED_BY', $userId)
			->where('DELETED_TYPE', ObjectTable::DELETED_TYPE_NONE)
			->where('ID', '!=', (int)$storage->getRootObjectId())
			->setLimit(1)
			->fetch()
		;

		return is_array($row);
	}

	/**
	 * @return array{entries: int, lastEntryDate: string|null, completed: bool}
	 */
	private function read(int $userId): array
	{
		$option = \CUserOptions::GetOption(Driver::INTERNAL_MODULE_ID, self::OPTION_NAME, [], $userId);
		if (!is_array($option))
		{
			$option = [];
		}

		$lastEntryDate = $option['lastEntryDate'] ?? null;

		return [
			'entries' => max(0, (int)($option['entries'] ?? 0)),
			'lastEntryDate' => is_string($lastEntryDate) ? $lastEntryDate : null,
			'completed' => (bool)($option['completed'] ?? false),
		];
	}

	/**
	 * @param array{entries: int, lastEntryDate: string|null, completed: bool} $state
	 */
	private function write(int $userId, array $state): void
	{
		\CUserOptions::SetOption(Driver::INTERNAL_MODULE_ID, self::OPTION_NAME, $state, false, $userId);
	}
}
