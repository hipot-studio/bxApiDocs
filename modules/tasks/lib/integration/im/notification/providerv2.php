<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Integration\IM\Notification;

use Bitrix\Main\Loader;
use Bitrix\Tasks\Integration\IM;
use Bitrix\Tasks\Integration\IM\Notification;
use Bitrix\Tasks\Integration\IM\Notification\UseCase\Flow\TaskAddedToFlowWithHimselfDistribution;
use Bitrix\Tasks\Integration\IM\Notification\UseCase\Flow\TaskAddedToFlowWithManualDistribution;
use Bitrix\Tasks\Integration\IM\Notification\UseCase\TaskCreatedV2;
use Bitrix\Tasks\Integration\IM\Notification\UseCase\TaskDeletedV2;
use Bitrix\Tasks\Integration\IM\Notification\UseCase\TaskUpdatedV2;
use Bitrix\Tasks\Integration\Mail\User;
use Bitrix\Tasks\Internals\Notification\EntityCode;
use Bitrix\Tasks\Internals\Notification\EntityOperation;
use Bitrix\Tasks\Internals\Notification\Message;
use Bitrix\Tasks\Internals\Notification\ProviderInterface;

class ProviderV2 implements ProviderInterface
{
	/** @var Message[]  */
	private array $messages = [];

	public function addMessage(Message $message): void
	{
		$this->messages[] = $message;
	}

	public function pushMessages(): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		foreach ($this->messages as $message) {
			if (!$this->isAllowedToPush($message))
			{
				continue;
			}

			$notification = $this->getNotification($message);
			if (!$notification)
			{
				continue;
			}

			$this->pushNotification($notification);
		}
	}

	private function isAllowedToPush(Message $message): bool
	{
		if (true === User::isEmail($message->getRecepient()->toArray()))
		{
			return false;
		}

		return true;
	}

	private function getNotification(Message $message): ?Notification
	{
		$metaData = $message->getMetaData();
		if ($metaData->getEntityCode() !== EntityCode::CODE_TASK)
		{
			return null;
		}

		$useCaseNotify = match ($metaData->getEntityOperation())
		{
			EntityOperation::ADD => new TaskCreatedV2(),
			EntityOperation::UPDATE => new TaskUpdatedV2(),
			EntityOperation::DELETE => new TaskDeletedV2(),
			EntityOperation::ADD_TO_FLOW_WITH_MANUAL_DISTRIBUTION => new TaskAddedToFlowWithManualDistribution(),
			EntityOperation::ADD_TO_FLOW_WITH_HIMSELF_DISTRIBUTION => new TaskAddedToFlowWithHimselfDistribution(),
			default => null,
		};

		return $useCaseNotify?->getNotification($message);
	}

	private function pushNotification(Notification $notification): void
	{
		$tag = $this->getNotificationTag($notification);
		$pushMessage = new Notification\Task\PushNotification($notification);

		$params = [
			'FROM_USER_ID' => $notification->getSender()->getId(),
			'TO_USER_ID' => $notification->getRecepient()->getId(),
			'NOTIFY_TYPE' => $notification->getNotifyType(),
			'NOTIFY_MODULE' => 'tasks',
			'NOTIFY_EVENT' => 'manage', // possibly different values
			'NOTIFY_TAG' => $tag->getName(),
			'NOTIFY_SUB_TAG' => $tag->getSubName(),
			'NOTIFY_MESSAGE' => (new Notification\Task\InstantNotification($notification))->getMessage(),
			'NOTIFY_MESSAGE_OUT' => (new Notification\Task\EmailNotification($notification))->getMessage(),
			'PUSH_MESSAGE' => $pushMessage->getMessage(),
			'PUSH_PARAMS' => $pushMessage->getParams($tag),
			'NOTIFY_BUTTONS' => $notification->getButtons(),
		];

		$params = array_merge($params, $notification->getParams());

		IM::notifyAdd($params);
	}

	private function getNotificationTag(Notification $notification): Tag
	{
		$message = $notification->getMessage();
		$metadata = $message->getMetaData();
		$task = $metadata->getTask();
		$params = $notification->getParams();

		return (new Tag())
			->setTasksIds($task ? [$task->getId()] : [])
			->setUserId($message->getRecepient()->getId())
			->setEntityCode($metadata->getEntityCode())
			->setActionName($params['action'] ?? '')
			->setEntityId($metadata->getCommentId() ?? 0);
	}
}