<?php
namespace Bitrix\Tasks\Grid\Task\Row\Content;

use Bitrix\Main;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;
use Bitrix\Tasks\Grid\ControlButtonTrait;
use Bitrix\Tasks\Grid\Task\Row\Content;
use Bitrix\Tasks\Integration\Bitrix24;
use Bitrix\Tasks\Util\Restriction\Bitrix24Restriction\Limit\ProjectLimit;

/**
 * Class Project
 *
 * @package Bitrix\Tasks\Grid\Task\Row\Content
 */
class Project extends Content
{
	use ControlButtonTrait;

	/**
	 * @return string
	 * @throws Main\ArgumentException
	 */
	public function prepare(): string
	{
		$row = $this->getRowData();
		$parameters = $this->getParameters();

		$groupId = (int)$row['GROUP_ID'];
		if (!$groupId)
		{
			if (!ProjectLimit::isFeatureEnabledOrTrial())
			{
				if (!$row['ACTION']['EDIT'])
				{
					return "<span></span>";
				}

				return $this->renderControlButton(
					[
						'style' => 'cursor: pointer;',
						'class' => 'tasks-list-tariff-lock-container',
						'aria-label' => GetMessage('TASKS_GRID_TASK_ROW_CONTENT_PROJECT_TARIFF_LOCK_MSGVER_1'),
					],
					'<span class="task-list-tariff-lock" aria-hidden="true"></span>',
					'event.stopPropagation(); BX.UI.InfoHelper.show(`limit_tasks_projects`)'
				);
			}

			if (!$row['ACTION']['EDIT'])
			{
				return "<span></span>";
			}

			$taskId = (int)$row['ID'];

			return '<div class="tasks-list-project-container">'
				. $this->renderControlButton(
					[
						'class' => 'tasks-list-project-add',
						'aria-label' => GetMessage('TASKS_GRID_TASK_ROW_CONTENT_PROJECT_ADD_MSGVER_1'),
					],
					'',
					"BX.PreventDefault(); BX.Tasks.GridActions.onProjectAddClick({$taskId}, this)"
				)
				. '</div>';
		}

		$groupName = htmlspecialcharsbx($row['GROUP_NAME']);
		$encodedData = Json::encode([
			'GROUP_ID' => [$groupId],
			'GROUP_ID_label' => [$groupName],
		]);

		$groupImage = '';
		if ($row['GROUP_IMAGE_ID'] > 0)
		{
			$arFile = \CFile::GetFileArray($row['GROUP_IMAGE_ID']);
			if (is_array($arFile))
			{
				$groupImage = $arFile['SRC'];
			}
		}

		$selected = 0;
		$selector = 'tasks-grid-group';
		if (
			isset($parameters['FILTER_FIELDS']['GROUP_ID'])
			&& is_array($parameters['FILTER_FIELDS']['GROUP_ID'])
			&& count($parameters['FILTER_FIELDS']['GROUP_ID']) === 1
			&& (int)$parameters['FILTER_FIELDS']['GROUP_ID'][0] === $groupId
		)
		{
			$selected = 1;
			$selector .= ' tasks-grid-filter-active';
		}

		$onClick = "BX.PreventDefault(); BX.Tasks.GridActions.toggleFilter({$encodedData}, {$selected})";
		if ($parameters['GROUP_ID'] > 0)
		{
			$onClick = '';
		}

		$iconClassList = [
			'tasks-grid-avatar',
		];

		$photo = (!empty($groupImage) ? '<i style="background-image: url(\'' . Uri::urnEncode($groupImage) . '\')"></i>' : '<i></i>');

		if (
			empty($groupImage)
			&& !empty($row['GROUP_AVATAR_TYPE'])
			&& Main\Loader::includeModule('socialnetwork')
		)
		{
			$iconClassList[] = 'sonet-common-workgroup-avatar';
			$iconClassList[] = '--' . htmlspecialcharsbx(\Bitrix\Socialnetwork\Helper\Workgroup::getAvatarTypeWebCssClass($row['GROUP_AVATAR_TYPE']));
		}
		else
		{
			$iconClassList[] = 'ui-icon';
			$iconClassList[] = 'ui-icon-common-user-group';
		}

		$iconClass = implode(' ', $iconClassList);

		$innerHtml = "<span class='{$iconClass}' aria-hidden='true'>{$photo}</span>"
			. "<span class='tasks-grid-group-inner'>{$groupName}</span>"
			. "<span class='tasks-grid-filter-remove'></span>";

		if ($onClick === '')
		{
			return "<span class='{$selector}' onclick='event.stopPropagation();'>{$innerHtml}</span>";
		}

		return $this->renderControlButton(
			[
				'class' => $selector,
				'aria-pressed' => ($selected ? 'true' : 'false'),
			],
			$innerHtml,
			$onClick,
			"'"
		);

	}
}