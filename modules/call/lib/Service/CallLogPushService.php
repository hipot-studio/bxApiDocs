<?php

namespace Bitrix\Call\Service;

use Bitrix\Main\Loader;

class CallLogPushService
{
	/**
	 * Send push event about changes in call logs
	 *
	 * The side of the row and its unseen mark come with $params: both are what the row carries after the
	 * write, and only the caller knows them.
	 *
	 * @internal Called by {@see \Bitrix\Call\Service\CallLogService::addOrUpdateEvent()} only.
	 * @param string $command
	 * @param array{
	 *     id: int,
	 *     sourceType: string,
	 *     sourceCallId: int,
	 *     userId: int,
	 *     status: string,
	 *     statusTime: string,
	 *     oldStatus?: string,
	 *     callData: array,
	 *     type: string,
	 *     isUnseen: bool,
	 * } $params
	 */
	public static function sendCallLog(string $command, array $params): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		if (!Loader::includeModule('im'))
		{
			return;
		}

		$pushMessage = [
			'module_id' => 'call',
			'command' => 'Call::callLog' . $command,
			'params' => $params,
			'extra' => \Bitrix\Im\Common::getPullExtra(),
		];

		\Bitrix\Pull\Event::add([$params['userId']], $pushMessage);
	}

	/**
	 * Send push event for missed calls counter update
	 *
	 * @internal Called by {@see \Bitrix\Call\Service\CallLogService::publishCounter()} only, which stores the
	 *           number before it is sent.
	 */
	public static function sendCounterUpdate(int $userId, int $counterValue, array $calls = []): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		if (!Loader::includeModule('im'))
		{
			return;
		}

		$pushMessage = [
			'module_id' => 'call',
			'command' => 'Call::callLogCounterUpdate',
			'params' => [
				'counterValue' => $counterValue,
				'callIds' => $calls,
			],
			'extra' => \Bitrix\Im\Common::getPullExtra(),
			'push' => [
				'send_immediately' => 'Y',
			],
		];

		\Bitrix\Pull\Event::add([$userId], $pushMessage);
	}
}
