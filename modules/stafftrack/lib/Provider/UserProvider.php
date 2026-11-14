<?php

namespace Bitrix\StaffTrack\Provider;

use Bitrix\Main;
use Bitrix\Main\File\Internal\FileVersionTable;
use Bitrix\Stafftrack\Integration\HumanResources\Structure;
use Bitrix\StaffTrack\Item\Collection\UserCollection;
use Bitrix\StaffTrack\Item\User;
use Bitrix\StaffTrack\Model\UserStatisticsHashTable;
use Bitrix\StaffTrack\Trait\Singleton;

class UserProvider
{
	use Singleton;

	protected const AVATAR_SIZE = 100;
	protected const FILE_CHUNK_SIZE = 500;
	protected const NAME_CHUNK_SIZE = 500;

	static protected array $users = [];

	public function getUsers(array $userIds): UserCollection
	{
		if (empty($userIds))
		{
			return new UserCollection([]);
		}

		$userEntities = Main\UserTable::query()
			->setSelect(['ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'EMAIL', 'PERSONAL_PHOTO', 'WORK_POSITION'])
			->whereIn('ID', $userIds)
			->fetchCollection()
		;

		$avatars = $this->getAvatars(array_map(
			static fn (Main\EO_User $userEO): int => (int)$userEO->getPersonalPhoto(),
			$userEntities->getAll(),
		));

		$users = [];
		$nameFormat = $this->getNameFormat();
		foreach ($userEntities as $userEO)
		{
			// The position and the photo are optional columns of the record, while the item takes them as
			// declared: an employee who filled in neither belongs in the list all the same.
			$users[] = new User(
				id: $userEO->getId(),
				name: \CUser::FormatName($nameFormat, $userEO->collectValues(), false, false),
				avatar: $avatars[(int)$userEO->getPersonalPhoto()] ?? '',
				workPosition: (string)$userEO->getWorkPosition(),
			);
		}

		return new UserCollection($users);
	}

	/**
	 * The names alone, for a list ordered by them before its page is read: no photo is touched.
	 *
	 * @param int[] $userIds
	 * @return array<int, string> user id => the name written the way the portal writes it
	 */
	public function getNames(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $id): bool => $id > 0,
		)));

		$names = [];
		$nameFormat = $this->getNameFormat();
		foreach (array_chunk($userIds, self::NAME_CHUNK_SIZE) as $chunk)
		{
			$rows = Main\UserTable::query()
				->setSelect(['ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'EMAIL'])
				->whereIn('ID', $chunk)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$names[(int)$row['ID']] = \CUser::FormatName($nameFormat, $row, false, false);
			}
		}

		return $names;
	}

	public function getUser(int $userId): ?User
	{
		self::$users[$userId] ??= $this->loadUser($userId);

		return self::$users[$userId];
	}

	protected function loadUser(int $userId): ?User
	{
		$nameFormat = $this->getNameFormat();
		$userEO = Main\UserTable::query()
			->setSelect([
				'ID',
				'NAME',
				'SECOND_NAME',
				'LAST_NAME',
				'EMAIL',
				'PERSONAL_PHOTO',
				'WORK_POSITION',
				'UF_DEPARTMENT',
			])
			->where('ID', $userId)
			->fetchObject()
		;

		if (!$userEO)
		{
			return null;
		}

		$departments = Structure::getInstance()->getDepartmentsByUserId($userEO->getId());

		return new User(
			id: $userEO->getId(),
			name: \CUser::FormatName($nameFormat, $userEO->collectValues(), false, false),
			avatar: $this->getUserAvatar((int)$userEO->getPersonalPhoto()),
			workPosition: (string)$userEO->getWorkPosition(),
			hash: $this->getUserHash($userEO->getId()),
			departments: $departments,
			isAdmin: $this->isUserAdmin($userEO->getId()),
		);
	}

	/**
	 * The name of a person is written the way the culture of the portal writes it, and the mail of
	 * the record takes part in that: a format that carries #EMAIL# is read from the same query.
	 */
	protected function getNameFormat(): string
	{
		return (string)Main\Application::getInstance()->getContext()->getCulture()->getNameFormat();
	}

	protected function getUserAvatar(int $imageId): string
	{
		if ($imageId <= 0)
		{
			return '';
		}

		return $this->resizeAvatar($imageId);
	}

	/**
	 * The thumbnails of many photos with a fixed number of queries. A photo stands for its latest version
	 * the way CFile::GetByID() picks it: an avatar with a mask is a version of the uploaded file.
	 *
	 * @param int[] $fileIds
	 * @return array<int, string> file id => link to the thumbnail
	 */
	protected function getAvatars(array $fileIds): array
	{
		$fileIds = array_values(array_unique(array_filter($fileIds, static fn (int $id): bool => $id > 0)));
		if ($fileIds === [])
		{
			return [];
		}

		$versionIds = $this->readFileVersionIds($fileIds);

		$readIds = array_values(array_unique([...$fileIds, ...$versionIds]));

		$files = [];
		foreach (array_chunk($readIds, self::FILE_CHUNK_SIZE) as $chunk)
		{
			$files += $this->readFiles($chunk);
		}

		$avatars = [];
		foreach ($fileIds as $fileId)
		{
			$latestId = max($fileId, $versionIds[$fileId] ?? 0);
			$file = $files[$latestId] ?? $files[$fileId] ?? null;

			$avatars[$fileId] = $file === null ? '' : $this->resizeAvatar($file);
		}

		return $avatars;
	}

	/**
	 * @param int[] $fileIds
	 * @return array<int, int> original file id => its latest version
	 */
	protected function readFileVersionIds(array $fileIds): array
	{
		$rows = FileVersionTable::query()
			->setSelect(['ORIGINAL_ID', 'VERSION_ID'])
			->whereIn('ORIGINAL_ID', $fileIds)
			->fetchAll()
		;

		$versionIds = [];
		foreach ($rows as $row)
		{
			$originalId = (int)$row['ORIGINAL_ID'];
			$versionIds[$originalId] = max($versionIds[$originalId] ?? 0, (int)$row['VERSION_ID']);
		}

		return $versionIds;
	}

	/**
	 * The records of b_file with every column, the way the handlers of a resize expect them.
	 *
	 * @param int[] $fileIds not empty: an empty filter reads the whole table
	 * @return array<int, array> file id => record with SRC
	 */
	protected function readFiles(array $fileIds): array
	{
		$files = [];

		$rows = \CFile::GetList([], ['@ID' => $fileIds]);
		while ($row = $rows->Fetch())
		{
			$row['SRC'] = \CFile::GetFileSRC($row);
			$files[(int)$row['ID']] = $row;
		}

		return $files;
	}

	protected function resizeAvatar(int|array $file): string
	{
		$image = \CFile::ResizeImageGet(
			$file,
			['width' => self::AVATAR_SIZE, 'height' => self::AVATAR_SIZE],
			BX_RESIZE_IMAGE_EXACT,
		);

		return !empty($image['src']) ? $image['src'] : '';
	}

	protected function getUserHash(int $userId): string
	{
		$user = UserStatisticsHashTable::query()
			->setSelect(['USER_ID', 'HASH'])
			->where('USER_ID', $userId)
			->fetchObject()
		;

		if ($user !== null)
		{
			return $user->getHash();
		}

		$hash = bin2hex(random_bytes(4));

		UserStatisticsHashTable::add([
			'USER_ID' => $userId,
			'HASH' => $hash,
		]);

		return $hash;
	}

	public function isUserAdmin(int $userId): bool
	{
		return in_array(1, $this->getUserGroupIds($userId), true);
	}

	protected function getUserGroupIds(int $userId): array
	{
		$userGroupIds = Main\UserTable::getUserGroupIds($userId);

		return array_map('intval', $userGroupIds);
	}
}