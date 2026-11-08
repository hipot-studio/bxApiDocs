<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base;

use Bitrix\Main;
use Bitrix\Main\Web\Json;

class UserFieldAssembler extends Main\Grid\Row\Assembler\Field\UserFieldAssembler
{
	/** @var array<int, array{NAME: string, PERSONAL_PHOTO: int}> Authors of the page, read at once. */
	private array $users = [];

	/** @var array<int, string> Resized avatars already asked for. */
	private array $avatarSrc = [];

	/**
	 * The authors of the whole page are read before the first row is drawn.
	 *
	 * A row draws its author by name and by avatar, and both of them used to be read per row: a page
	 * of twenty rows with two author columns cost eighty queries before it was drawn.
	 */
	public function prepareRows(array $rowList): array
	{
		$this->loadUsers($this->collectUserIds($rowList));

		return parent::prepareRows($rowList);
	}

	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];

		foreach ($this->getColumnIds() as $columnId)
		{
			$value = $row['data'];
			$row['columns'][$columnId] = $this->prepareColumn($value);
		}

		return $row;
	}

	protected function loadUserName(int $userId): string
	{
		return $this->getUser($userId)['NAME'];
	}

	protected function getAvatarSrc(int $avatarId, int $width = 22, int $height = 22): string
	{
		$cacheKey = "{$avatarId}_{$width}_{$height}";
		if (isset($this->avatarSrc[$cacheKey]))
		{
			return $this->avatarSrc[$cacheKey];
		}

		$src = '';
		$imageFile = \CFile::getFileArray($avatarId);
		if ($imageFile !== false)
		{
			$fileTmp = \CFile::resizeImageGet(
				$imageFile,
				compact('width', 'height'),
				BX_RESIZE_IMAGE_EXACT,
				false
			);

			$src = (string)$fileTmp['src'];
		}

		$this->avatarSrc[$cacheKey] = $src;

		return $src;
	}

	protected function prepareColumnWithParams($value, string $fieldId, string $clickHandler): string
	{
		$ormFilter = $this->getSettings()->getOrmFilter();
		$isFiltered = isset($ormFilter[$fieldId], $value[$fieldId]) && in_array((int)$value[$fieldId], $ormFilter[$fieldId]);

		$userId = (int)($value[$fieldId] ?? 0);

		if ($userId > 0)
		{
			$user = $this->getUser($userId);
			$avatar = '';
			if ($user['PERSONAL_PHOTO'] > 0)
			{
				$avatarSrc = $this->getAvatarSrc($user['PERSONAL_PHOTO']);
				$avatar = " style=\"background-image: url('{$avatarSrc}');\"";
			}

			$userName = $user['NAME'];
			$event = Json::encode([
				'ID' => $userId,
				'TITLE' => $userName,
				'IS_FILTERED' => $isFiltered,
			]);

			$selector = 'biconnector-grid-username-cell';
			$closeIcon = '';
			if ($isFiltered)
			{
				$selector .= ' biconnector-grid-username-cell-active';
				$closeIcon = '<div class="biconnector-grid-filter-close ui-icon-set --cross-20"></div>';
			}

			return <<<HTML
				<span
					class="$selector"
					onclick='{$clickHandler}($event)'
				>
					<span class="biconnector-grid-avatar ui-icon ui-icon-common-user">
						<i{$avatar}></i>
					</span>
					<span class="biconnector-grid-username">$userName</span>
					$closeIcon
				</span>
			HTML;

		}

		return '';
	}

	/**
	 * @return array{NAME: string, PERSONAL_PHOTO: int} A user nobody found names nobody, and the cell
	 *                                                  of such a row stays without a name and a face.
	 */
	private function getUser(int $userId): array
	{
		if (!isset($this->users[$userId]))
		{
			// A row drawn outside prepareRows(), one grid action for instance, asks about its own author.
			$this->loadUsers([$userId]);
		}

		return $this->users[$userId];
	}

	/**
	 * @param array<int, array<string, mixed>> $rowList
	 *
	 * @return int[]
	 */
	private function collectUserIds(array $rowList): array
	{
		$userIds = [];
		foreach ($rowList as $row)
		{
			foreach ($this->getColumnIds() as $columnId)
			{
				$userId = (int)($row['data'][$columnId] ?? 0);
				if ($userId > 0)
				{
					$userIds[$userId] = $userId;
				}
			}
		}

		return array_values($userIds);
	}

	/**
	 * @param int[] $userIds
	 */
	private function loadUsers(array $userIds): void
	{
		$userIds = array_values(array_diff($userIds, array_keys($this->users)));
		if ($userIds === [])
		{
			return;
		}

		$nameFormat = \CSite::getNameFormat();
		$rows = Main\UserTable::getList([
			'select' => [
				'ID',
				'LOGIN',
				'NAME',
				'LAST_NAME',
				'SECOND_NAME',
				'EMAIL',
				'TITLE',
				'PERSONAL_PHOTO',
			],
			'filter' => ['@ID' => $userIds],
		])->fetchAll();

		foreach ($rows as $row)
		{
			$this->users[(int)$row['ID']] = [
				'NAME' => (string)\CUser::formatName($nameFormat, $row, true, true),
				'PERSONAL_PHOTO' => (int)$row['PERSONAL_PHOTO'],
			];
		}

		// A deleted author is remembered as an unknown one: the row still asks about them, and asking
		// the base a second time would cost a query per row all the same.
		foreach ($userIds as $userId)
		{
			$this->users[$userId] ??= ['NAME' => '', 'PERSONAL_PHOTO' => 0];
		}
	}
}
