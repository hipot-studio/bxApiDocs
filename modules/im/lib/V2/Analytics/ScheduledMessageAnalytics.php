<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Im\V2\Analytics\Event\ScheduledMessageEvent;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Scheduled\ScheduledMessage;
use DateInterval;

class ScheduledMessageAnalytics extends AbstractAnalytics
{
	protected const CREATE = 'create_scheduled_message';
	protected const SEND = 'send_message';
	protected const DELETE = 'scheduled_message_delete';

	public const STATUS_SUCCESS = 'success';
	public const STATUS_ERROR_NO_PERMISSION = 'error_no_permission';
	public const STATUS_ERROR_USER_DEACTIVATED = 'error_user_deactivated';
	public const STATUS_ERROR_CHAT_DELETED = 'error_chat_deleted';
	public const STATUS_ERROR_PER_CHAT_LIMIT_EXCEEDED = 'error_per_chat_limit_exceeded';
	public const STATUS_ERROR_SEND_AT_OUT_OF_RANGE = 'error_send_at_out_of_range';
	public const STATUS_ERROR_INVALID_UUID = 'error_invalid_uuid';
	public const STATUS_ERROR_INVALID_FIELDS = 'error_invalid_fields';
	public const STATUS_ERROR_SENDING_WINDOW_PASSED = 'error_sending_window_passed';
	public const STATUS_ERROR_CREATE_FAILED = 'error_create_failed';
	public const STATUS_ERROR_SEND_FAILED = 'error_send_failed';
	public const STATUS_ERROR_DELETE_FAILED = 'error_delete_failed';

	public const SEND_MESSAGE_TYPE_MANUAL = 'messageScheduled_manual';
	public const SEND_MESSAGE_TYPE_SCHEDULED = 'messageScheduled_scheduled';

	public function addCreate(?DateInterval $delay = null, string $status = self::STATUS_SUCCESS): void
	{
		$this->async(function () use ($delay, $status) {
			$event = $this->createScheduledMessageEvent(self::CREATE);
			if ($event === null)
			{
				return;
			}

			$event->setStatus($status);

			if ($delay !== null)
			{
				$event->setSendDelayP3($delay);
			}

			$event->send();
		});
	}

	public function addSend(
		ScheduledMessage $scheduledMessage,
		string $status,
		string $sendMessageAnalyticsType
	): void
	{
		$message = (new Message())
			->setAuthorId($scheduledMessage->getAuthorId())
			->setMessage($scheduledMessage->getMessage())
		;

		$messageType = (new MessageContent($message))->getComponentName();

		$this->async(function () use ($status, $sendMessageAnalyticsType, $messageType) {
			$this
				->createScheduledMessageEvent(self::SEND)
				?->setType($messageType)
				?->setStatus($status)
				?->setP3($sendMessageAnalyticsType)
				?->send()
			;
		});
	}

	public function addDelete(string $status = self::STATUS_SUCCESS): void
	{
		$this->async(function () use ($status) {
			$this
				->createScheduledMessageEvent(self::DELETE)
				?->setStatus($status)
				?->send()
			;
		});
	}

	protected function createScheduledMessageEvent(string $eventName): ?ScheduledMessageEvent
	{
		if (!$this->isChatTypeAllowed($this->chat, $eventName))
		{
			return null;
		}

		$authorId = $this->getContext()->getUserId();
		$chat = $this->chat->withContextUser($authorId);

		return new ScheduledMessageEvent($eventName, $chat, $authorId);
	}
}
