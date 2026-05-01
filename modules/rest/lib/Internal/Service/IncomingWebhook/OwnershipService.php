<?php

namespace Bitrix\Rest\Internal\Service\IncomingWebhook;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\APAuth\PasswordTable;

final class OwnershipService
{
	public function changeOwner(int $fromUserId, int $toUserId, ?array $webhookIds = null): Result
	{
		$result = new Result();

		if ($webhookIds === [])
		{
			$result->addError(new Error('Webhook ids is empty'));
			return $result;
		}

		if ($webhookIds === null)
		{
			$webhooks = PasswordTable::getList([
				'filter' => [
					'=USER_ID' => $fromUserId,
					'=ACTIVE' => PasswordTable::ACTIVE,
				],
				'select' => ['ID']
			]);
		}
		else
		{
			$webhooks = PasswordTable::getList([
				'filter' => [
					'=USER_ID' => $fromUserId,
					'=ACTIVE' => PasswordTable::ACTIVE,
					'=ID' => $webhookIds,
				],
				'select' => ['ID']
			]);
		}

		$updateWebhookIds = array_column($webhooks->fetchAll(), 'ID');

		if (empty($updateWebhookIds))
		{
			return $result;
		}

		PasswordTable::updateMulti($updateWebhookIds, ['USER_ID' => $toUserId]);

		$event = new \Bitrix\Main\Event('rest', 'onIncomingWebhookOwnerChanged', [
			'originalUserId' => $fromUserId,
			'newUserId' => $toUserId,
			'updateWebhookIds' => $updateWebhookIds,
		]);
		$event->send();

		return $result;
	}
}