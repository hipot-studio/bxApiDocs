<?php
namespace Bitrix\Tasks\Grid\Task\Row\Content;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Util\User;

/**
 * Class MemberList
 *
 * Renders a grid cell with the list of task members (accomplices or auditors) as a stack of avatars
 * with an overflow counter. The full list is rendered only for the lazy popup request.
 *
 * @package Bitrix\Tasks\Grid\Task\Row\Content
 */
abstract class MemberList extends UserName
{
	/** Row field holding the array of member user ids (e.g. ACCOMPLICES, AUDITORS). */
	protected const SOURCE_FIELD = '';

	/** Plural message id of the cell aria-label. */
	protected const LABEL_MESSAGE_ID = '';

	public const VISIBLE_MEMBERS_COUNT = 3;

	/**
	 * @return string
	 * @throws Main\ArgumentException
	 */
	public function prepare(): string
	{
		return $this->prepareMemberList();
	}

	/**
	 * @throws Main\ArgumentException
	 */
	protected function prepareMemberList(): string
	{
		$userIds = $this->collectMemberIds();
		$visibleUserIds = array_slice($userIds, 0, static::VISIBLE_MEMBERS_COUNT);
		$members = $this->collectMembers($visibleUserIds);
		$totalCount = count($userIds) - (count($visibleUserIds) - count($members));
		if ($totalCount === 0)
		{
			return '';
		}

		return $this->buildMemberListMarkup($members, $totalCount);
	}

	/**
	 * @throws Main\ArgumentException
	 */
	public function preparePopup(): string
	{
		return $this->buildFullListMarkup($this->collectMembers($this->collectMemberIds()));
	}

	/**
	 * A declared MEMBERS field is the complete profile source for the current render. A missing profile must
	 * not fall back to a row-level query. An undeclared field keeps the single member fallback.
	 *
	 * @return array|null
	 * @throws Main\ArgumentException
	 */
	protected function resolveMember(int $userId): ?array
	{
		$members = (array)($this->getRowData()['MEMBERS'] ?? []);

		if (array_key_exists(static::SOURCE_FIELD, $members))
		{
			return $members[static::SOURCE_FIELD][$userId] ?? null;
		}

		return static::getCachedUser($userId);
	}

	/**
	 * @return int[]
	 */
	private function collectMemberIds(): array
	{
		$row = $this->getRowData();
		$userIds = $row['MEMBER_IDS'][static::SOURCE_FIELD] ?? $row[static::SOURCE_FIELD] ?? [];
		$userIds = array_map('intval', (array)$userIds);
		$userIds = array_filter($userIds, static fn(int $userId): bool => $userId > 0);

		return array_values(array_unique($userIds));
	}

	/**
	 * @param int[] $userIds
	 * @return array[] members with resolved user data, in the row order
	 * @throws Main\ArgumentException
	 */
	private function collectMembers(array $userIds): array
	{
		$members = [];
		foreach ($userIds as $userId)
		{
			$user = $this->resolveMember($userId);
			if ($user === null)
			{
				continue;
			}

			$members[] = $user;
		}

		return $members;
	}

	private function buildMemberListMarkup(array $members, int $totalCount): string
	{
		$label = htmlspecialcharsbx($this->buildCellLabel($totalCount));
		$taskId = (int)($this->getRowData()['ID'] ?? 0);
		$testId = 'tasks-grid-member-list-' . mb_strtolower(static::USER_ROLE) . '-' . $taskId;
		$selectedUserId = $this->getSelectedUserId();

		return '<div class="tasks-grid-member-list" role="button" tabindex="0" aria-haspopup="dialog"'
			. ' aria-expanded="false" aria-label="' . $label . '" data-testid="' . $testId . '"'
			. ' data-task-id="' . $taskId . '" data-member-role="' . static::USER_ROLE . '"'
			. ' data-selected-user-id="' . $selectedUserId . '">'
				. '<div class="tasks-grid-member-list__stack" aria-hidden="true">'
					. $this->buildAvatarStackMarkup($members, $totalCount)
				. '</div>'
				. '<div class="tasks-grid-member-list__full"></div>'
			. '</div>';
	}

	private function getSelectedUserId(): int
	{
		$selectedUserIds = $this->getParameter('FILTER_FIELDS')[static::USER_ROLE] ?? [];

		return is_array($selectedUserIds) && count($selectedUserIds) === 1
			? (int)$selectedUserIds[0]
			: 0
		;
	}

	/**
	 * The cell content is hidden from assistive technology, so the label is the only carrier of the
	 * member count.
	 */
	private function buildCellLabel(int $count): string
	{
		$replace = ['#COUNT#' => (string)$count];

		$label = (string)Loc::getMessagePlural(static::LABEL_MESSAGE_ID, $count, $replace);
		if ($label === '')
		{
			// Use the source locale as a fallback so the cell button keeps its accessible name.
			$label = (string)Loc::getMessagePlural(static::LABEL_MESSAGE_ID, $count, $replace, 'ru');
		}

		return $label;
	}

	private function buildAvatarStackMarkup(array $members, int $totalCount): string
	{
		$markup = '';
		foreach ($members as $user)
		{
			$markup .= $this->buildAvatarMarkup($user, User::formatName($user));
		}

		$hiddenCount = $totalCount - count($members);
		if ($hiddenCount > 0)
		{
			$markup .= '<span class="tasks-grid-member-list__counter"'
				. ' data-testid="tasks-grid-member-list-counter">+' . $hiddenCount . '</span>';
		}

		return $markup;
	}

	private function buildFullListMarkup(array $members): string
	{
		$markup = '';
		foreach ($members as $user)
		{
			$markup .= $this->buildUserMarkup($user, static::USER_ROLE);
		}

		return $markup;
	}
}
