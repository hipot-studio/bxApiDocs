<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Engine\Response\Component;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Internal\Service\TaskLegacyFeatureService;

class LegacyComment extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.LegacyComment.get
	 */
	#[CloseSession]
	public function getAction(
		#[Permission\Read] Entity\Task $task,
	): ?Component
	{
		if (!(new TaskLegacyFeatureService())->hasForumComments($task->getId()))
		{
			return null;
		}

		return new Component(
			"bitrix:forum.comments", "bitrix24", [
			"FORUM_ID" => \Bitrix\Tasks\Integration\Forum\Task\Comment::getForumId(),
			"ENTITY_TYPE" => "TK",
			"ENTITY_ID" => $task->getId(),
			"ENTITY_XML_ID" => 'TASK_' . $task->getId(),
			"PERMISSION" => 'E', // read-only
			"URL_TEMPLATES_PROFILE_VIEW" => "/company/personal/user/#user_id#/",
			"SHOW_RATING" => 'N',
			"SHOW_LINK_TO_MESSAGE" => "N",
			"BIND_VIEWER" => "N",
			"MESSAGES_PER_PAGE" => 5,
			'SHOW_POST_FORM' => 'Y',
			'MESSAGE_COUNT' => 5,
			"PUBLIC_MODE" => "Y",
			"SHOW_SUBSCRIBE" => "N",
		],
			['HIDE_ICONS' => 'Y']
		);
	}
}
