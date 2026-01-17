<?php

namespace Bitrix\Call\Service;

use Bitrix\Main\Loader;

class CallLogPushService
{
	/**
	 * Send push event about changes in call logs
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

		$params['type'] = $params['status'] === 'initiated' ? 'outgoing' : 'incoming';
		$params['isUnseen'] = $params['status'] === 'missed';

		$pushMessage = [
			'module_id' => 'call',
			'command' => 'Call::callLog' . $command,
			'params' => $params,
			'extra' => \Bitrix\Im\Common::getPullExtra()
		];

		\Bitrix\Pull\Event::add([$params['userId']], $pushMessage);
	}

	/**
	 * Send push event for missed calls counter update
	 */
	public static function sendCounterUpdate(int $userId, int $counterValue): void
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
				'counterValue' => $counterValue
			],
			'extra' => \Bitrix\Im\Common::getPullExtra()
		];

		\Bitrix\Pull\Event::add([$userId], $pushMessage);
	}
}
