<?php

namespace Bitrix\Tasks\Grid\Scrum\Row\Content;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Grid\ControlButtonTrait;
use Bitrix\Tasks\Grid\Scrum\Row\Content;

Loc::loadMessages(__FILE__);

/**
 * Class Members
 *
 * @package Bitrix\Tasks\Grid\Project\Row\Content
 */
class Members extends Content
{
	use ControlButtonTrait;

	public function prepare(): string
	{
		$row = $this->getRowData();
		$visibleMembersCount = 3;

		$headsLayout = '';
		$heads = $this->fillUsersLayout(($row['MEMBERS']['HEADS'] ?? []));
		if (count($heads) > 0)
		{
			$innerLayouts = [];
			foreach ($heads as $head)
			{
				if ($head['IS_OWNER'] === 'Y')
				{
					array_unshift($innerLayouts, $head['LAYOUT']);

					continue;
				}
				$innerLayouts[] = $head['LAYOUT'];
			}
			if (count($innerLayouts) > $visibleMembersCount)
			{
				$innerLayouts = array_slice($innerLayouts, 0, $visibleMembersCount);
			}
			$innerLayouts =	implode("\n", $innerLayouts);

			$headsLayout
				= "<span style='display: inline-block'>"
					. "<span class='tasks-projects-user-list tasks-projects-user-list--green'>"
						. $innerLayouts
						. $this->makeOtherCounterLayout(((int)$row['NUMBER_OF_MODERATORS'] - $visibleMembersCount))
					. "</span>"
				. "</span>";
		}

		$usersLayout = '';
		$users = $this->fillUsersLayout(($row['MEMBERS']['MEMBERS'] ?? []));
		$users = array_filter(
			$users,
			static function ($user) {
				return $user['IS_ACCESS_REQUESTING'] !== 'Y';
			},
		);
		if (count($users) > 0)
		{
			$innerLayouts = [];
			foreach ($users as $user)
			{
				$innerLayouts[] = $user['LAYOUT'];
				if (count($innerLayouts) >= $visibleMembersCount)
				{
					break;
				}
			}
			$innerLayouts = implode("\n", $innerLayouts);

			$usersLayout
				= "<span style='display: inline-block'>"
					. "<span class='tasks-projects-user-list'>"
						. $innerLayouts
						. $this->makeOtherCounterLayout(
							((int)$row['NUMBER_OF_MEMBERS'] - (int)$row['NUMBER_OF_MODERATORS'] - $visibleMembersCount),
						)
					. "</span>"
				. "</span>";
		}

		return $this->renderControlButton(
			[
				'class' => 'tasks-projects-user-list-container',
				'aria-label' => Loc::getMessage('TASKS_GRID_SCRUM_ROW_CONTENT_MEMBERS_OPEN_LIST'),
				'onclick' => $this->getMembersPopupShowFunction(),
			],
			$headsLayout . $usersLayout,
		);
	}

	private function fillUsersLayout(array $users): array
	{
		foreach ($users as $id => $user)
		{
			$style = ($user['PHOTO'] ? "style='background-image: url(\"{$user['PHOTO']}\")'" : '');
			$users[$id]['LAYOUT']
				= "<span class='tasks-projects-user-item' {$style}>"
					. "<span class='tasks-projects-user-crown'></span>"
				. "</span>";
		}

		return $users;
	}

	private function makeOtherCounterLayout(int $otherCount): string
	{
		if ($otherCount <= 0)
		{
			return "";
		}

		return "<span class='tasks-projects-user-count'><span class='tasks-projects-user-plus'>+</span>{$otherCount}</span>";
	}

	private function getMembersPopupShowFunction(): string
	{
		$row = $this->getRowData();
		$projectId = (int)$row['ID'];

		return "BX.Tasks.ProjectsInstance.getScrumMembersPopup().show({$projectId}, this); event.stopPropagation();";
	}
}
