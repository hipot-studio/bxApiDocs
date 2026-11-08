<?php

namespace Bitrix\Tasks\Grid\Scrum\Row\Content;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Grid\ControlButtonTrait;
use Bitrix\Tasks\Grid\Scrum\Row\Content;

Loc::loadMessages(__FILE__);

/**
 * Class Role
 *
 * @package Bitrix\Tasks\Grid\Project\Row\Content
 */
class Role extends Content
{
	use ControlButtonTrait;

	public function prepare(): string
	{
		$row = $this->getRowData();
		$parameters = $this->getParameters();

		$userId = $parameters['USER_ID'];
		$userData = [];

		if (array_key_exists($userId, $row['MEMBERS']['HEADS']))
		{
			$userData = $row['MEMBERS']['HEADS'][$userId];
		}
		elseif (array_key_exists($userId, $row['MEMBERS']['MEMBERS']))
		{
			$userData = $row['MEMBERS']['MEMBERS'][$userId];
		}

		if (empty($userData))
		{
			return $this->createRequestButton();
		}

		if ($userData['IS_ACCESS_REQUESTING'] === 'Y')
		{
			return $this->createRequestingLayout($userData);
		}

		return $this->createRoleLayout($userData);
	}

	private function createRequestButton(): string
	{
		$row = $this->getRowData();
		$text = Loc::getMessage('TASKS_GRID_SCRUM_ROW_CONTENT_ROLE_JOIN');

		if ($row['OPENED'] === 'Y')
		{
			$requestUrl = \CComponentEngine::makePathFromTemplate(
				$this->getParameters()['PATH_TO_USER_REQUEST_GROUP'],
				['group_id' => $row['ID']],
			);

			return $this->renderControlButton(
				[
					'class' => 'ui-label tasks-projects-badge-join',
					'id' => "requestButton{$row['ID']}",
					'bx-request-url' => $requestUrl,
					'onclick' => 'event.stopPropagation(); BX.Tasks.Projects.ActionsController.sendJoinRequest(this)',
				],
				"<span class='ui-label-inner'>{$text}</span>",
			);
		}

		return
			"<a class='ui-label tasks-projects-badge-join' href='/workgroups/group/{$row['ID']}/user_request/'>
				<span class='ui-label-inner'>{$text}</span>
			</a>";
	}

	private function createRequestingLayout(array $user): string
	{
		$requestPath = \CComponentEngine::makePathFromTemplate(
			$this->getParameters()['PATH_TO_USER_REQUESTS'],
			['user_id' => $user['ID']],
		);
		$userGroupRelationId = (int)$this->getRowData()['USER_GROUP_ID'];
		$actionsController = 'BX.Tasks.Projects.ActionsController.';

		if ($user['IS_ACCESS_REQUESTING_BY_ME'] === 'Y')
		{
			$text = Loc::getMessage('TASKS_GRID_SCRUM_ROW_CONTENT_ROLE_REQUEST_SENT');
			$cancelButton = $this->renderControlButton(
				[
					'class' => 'ui-label tasks-projects-badge-cancel',
					'aria-label' => Loc::getMessage('TASKS_GRID_SCRUM_ROW_CONTENT_ROLE_CANCEL_REQUEST'),
					'onclick' => 'event.stopPropagation(); '
						. $actionsController
						. "sendCancelRequest({$userGroupRelationId}, \"{$requestPath}\");",
				],
				'<span class="ui-label-inner"></span>',
			);

			return
				"
				<div class='tasks-projects-badge-invite-box'>
					<div class='ui-label tasks-projects-badge-invite'>
						<span class='ui-label-inner'>{$text}</span>
					</div>
					{$cancelButton}
				</div>
				";
		}

		$text = Loc::getMessage('TASKS_GRID_SCRUM_ROW_CONTENT_ROLE_INVITED');
		$acceptButton = $this->renderControlButton(
			[
				'class' => 'ui-label tasks-projects-badge-accept',
				'aria-label' => Loc::getMessage('TASKS_GRID_SCRUM_ROW_CONTENT_ROLE_ACCEPT_INVITATION'),
				'onclick' => 'event.stopPropagation(); '
					. $actionsController
					. "sendAcceptRequest({$userGroupRelationId}, \"{$requestPath}\");",
			],
			'<span class="ui-label-inner"></span>',
		);
		$denyButton = $this->renderControlButton(
			[
				'class' => 'ui-label tasks-projects-badge-cancel',
				'aria-label' => Loc::getMessage('TASKS_GRID_SCRUM_ROW_CONTENT_ROLE_DENY_INVITATION'),
				'onclick' => 'event.stopPropagation(); '
					. $actionsController
					. "sendDenyRequest({$userGroupRelationId}, \"{$requestPath}\");",
			],
			'<span class="ui-label-inner"></span>',
		);

		return
			"
			<div class='tasks-projects-badge-invite-box'>
				<div class='ui-label tasks-projects-badge-invite-accept'>
					<span class='ui-label-inner'>{$text}</span>
				</div>
				{$acceptButton}
				{$denyButton}
			</div>
			";
	}

	private function createRoleLayout(array $user): string
	{
		$roles = [
			'owner' => [
				'text' => Loc::getMessage('TASKS_GRID_SCRUM_ROW_CONTENT_ROLE_OWNER'),
				'color' => 'green',
			],
			'moderator' => [
				'text' => Loc::getMessage('TASKS_GRID_SCRUM_ROW_CONTENT_ROLE_MODERATOR'),
				'color' => 'yellow',
			],
			'member' => [
				'text' => Loc::getMessage('TASKS_GRID_SCRUM_ROW_CONTENT_ROLE_MEMBER'),
				'color' => 'blue',
			],
		];

		$role = $roles['member'];
		if ($user['IS_OWNER'] === 'Y')
		{
			$role = $roles['owner'];
		}
		elseif ($user['IS_MODERATOR'] === 'Y')
		{
			$role = $roles['moderator'];
		}

		return
			"<div class='ui-label tasks-projects-badge-{$role['color']}' onclick='event.stopPropagation()'>"
			. "<span class='ui-label-inner'>{$role['text']}</span>"
			. "</div>";
	}
}
