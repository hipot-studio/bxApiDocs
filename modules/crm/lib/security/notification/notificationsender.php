<?php

namespace Bitrix\Crm\Security\Notification;

use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Loader;
use CIMNotify;

final class NotificationSender
{
	use Singleton;

	public function send(array $data, array $titles): void
	{
		if (!$this->canSend())
		{
			return;
		}

		$toUserId = $data['toUserId'];
		$fromUserId = $data['fromUserId'];

		if ($toUserId === $fromUserId)
		{
			return;
		}

		$automatedSolutionId = $data['automatedSolutionId'];

		$fields = [
			'TO_USER_ID' => $toUserId,
			'FROM_USER_ID' => $fromUserId,
			'NOTIFY_EVENT' => 'other',
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'crm',
			'NOTIFY_TAG' => 'CRM|CUSTOM_SECTION|READ_PERMISSION|' . $toUserId . '|' . $fromUserId . '|' . $automatedSolutionId,
			'NOTIFY_MESSAGE' => $titles['titlePlainCallback'],
			'NOTIFY_BUTTONS' => [],
			'PARAMS' => [
				'COMPONENT_ID' => 'BizprocEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $titles['titleCallback'],
					'ENTITY' => [
						'TITLE' => $data['automatedSolutionTitle'],
						'HREF' => $data['sectionHref'],
						'ENTITY_TYPE' => 'customSection',
						'CONTENT_TYPE' => 'title',
					],
				],
			],
		];

		CIMNotify::Add($fields);
	}

	private function canSend(): bool
	{
		return Loader::includeModule('im');
	}
}
