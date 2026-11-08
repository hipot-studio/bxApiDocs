<?php

namespace Bitrix\Forum\Infrastructure\Rest\Service;

use Bitrix\Main\Loader;

final class MessageDeleteService
{
	public function delete(int $messageId): bool
	{
		$result = \CForumMessage::Delete($messageId);
		if (!$result || !Loader::includeModule('socialnetwork'))
		{
			return $result;
		}

		$logIdList = [];
		$logResult = \Bitrix\Socialnetwork\LogTable::getList([
			'filter' => [
				'=SOURCE_ID' => $messageId,
				'@EVENT_ID' => ['forum'],
			],
			'select' => ['ID'],
		]);
		while ($logFields = $logResult->fetch())
		{
			if (\CSocNetLog::delete($logFields['ID']))
			{
				$logIdList[] = (int)$logFields['ID'];
			}
		}

		if (empty($logIdList))
		{
			$logCommentResult = \Bitrix\Socialnetwork\LogCommentTable::getList([
				'filter' => [
					'=SOURCE_ID' => $messageId,
					'@EVENT_ID' => [
						'forum',
						'tasks_comment',
						'calendar_comment',
						'timeman_entry_comment',
						'report_comment',
						'photo_comment',
						'wiki_comment',
						'lists_new_element_comment',
					],
				],
				'select' => ['ID', 'LOG_ID'],
			]);
			while ($logCommentFields = $logCommentResult->fetch())
			{
				if (\CSocNetLogComments::delete($logCommentFields['ID']))
				{
					$logIdList[] = (int)$logCommentFields['LOG_ID'];
				}
			}
		}

		if (!empty($logIdList))
		{
			$cache = new \CPHPCache();
			foreach ($logIdList as $logId)
			{
				$cache->CleanDir('/sonet/log/' . (int)($logId / 1000) . '/' . $logId . '/comments/');
			}
		}

		return true;
	}
}
