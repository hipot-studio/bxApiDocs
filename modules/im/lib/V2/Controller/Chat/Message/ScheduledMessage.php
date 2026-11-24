<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat\Message;

use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Message\Scheduled\Create\ScheduledMessageFields;
use Bitrix\Im\V2\Message\Scheduled\Error\ScheduledMessageError;
use Bitrix\Im\V2\Message\Scheduled\GetList\ScheduledMessageCursor;
use Bitrix\Im\V2\Message\Scheduled\ScheduledMessageProvider;
use Bitrix\Im\V2\Message\Scheduled\ScheduledMessageService;
use Bitrix\Im\V2\Message\Scheduled\Update\UpdateFields;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Engine\AutoWire\ValidationParameter;
use DateTimeInterface;
use Bitrix\Main\Config\Feature;
use Bitrix\Im\Public\Features\ScheduledMessages;

class ScheduledMessage extends BaseController
{
	public function getAutoWiredParameters(): array
	{
		return array_merge(
			parent::getAutoWiredParameters(),
			[
				new ValidationParameter(
					ScheduledMessageFields::class,
					fn ($className, array $fields): ScheduledMessageFields
						=> ScheduledMessageFields::fromArray($fields),
					fn () => 'fields',
				),
				new ValidationParameter(
					UpdateFields::class,
					fn ($className, array $fields): UpdateFields
					=> UpdateFields::fromArray($fields),
					fn () => 'fields',
				),
				new ValidationParameter(
					ScheduledMessageCursor::class,
					fn ($className, array $cursor): ScheduledMessageCursor
						=> ScheduledMessageCursor::fromArray($cursor),
					fn() => 'cursor',
				),
				new ExactParameter(
					\Bitrix\Im\V2\Message\Scheduled\ScheduledMessage::class,
					'scheduledMessage',
					function ($className, int $id)
					{
						$scheduledMessage = ServiceLocator::getInstance()
							->get(ScheduledMessageProvider::class)
							->getById($id)
						;

						if ($scheduledMessage === null)
						{
							$this->addError(new ScheduledMessageError(ScheduledMessageError::NOT_FOUND));

							return null;
						}

						return $scheduledMessage;
					}
				),
				new ExactParameter(
					DateTime::class,
					'sendAt',
					function ($className, string $sendAt)
					{
						$sendAt = DateTime::tryParse($sendAt, DateTimeInterface::RFC3339);

						if ($sendAt === null)
						{
							$this->addError(new ScheduledMessageError(ScheduledMessageError::INVALID_SEND_AT));

							return null;
						}

						return $sendAt;
					}
				),
			],
		);
	}

	/**
	 * @restMethod im.v2.Chat.Message.ScheduledMessage.create
	 */
	public function createAction(
		ScheduledMessageFields $fields,
		CurrentUser $user,
		ScheduledMessageService $scheduledMessageService
	): ?array
	{
		if (!$this->checkAvailability())
		{
			return null;
		}

		$result = $scheduledMessageService->create($fields, (int)$user->getId());
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$scheduledMessage = $result->getScheduledMessage();

		return $scheduledMessage !== null ? $this->toRestFormat($scheduledMessage) : null;
	}

	/**
	 * @restMethod im.v2.Chat.Message.ScheduledMessage.sendNow
	 */
	public function sendNowAction(
		\Bitrix\Im\V2\Message\Scheduled\ScheduledMessage $scheduledMessage,
		ScheduledMessageService $scheduledMessageService
	): ?array
	{
		if (!$this->checkAvailability())
		{
			return null;
		}

		$result = $scheduledMessageService->sendNow($scheduledMessage);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$messageId = $result->getMessageId();

		return $messageId !== null ? ['messageId' => $messageId] : null;
	}

	/**
	 * @restMethod im.v2.Chat.Message.ScheduledMessage.getList
	 */
	public function getListAction(
		int $chatId,
		CurrentUser $user,
		ScheduledMessageService $scheduledMessageService,
		int $limit = ScheduledMessageService::DEFAULT_LIST_LIMIT,
		?ScheduledMessageCursor $cursor = null
	): ?array
	{
		if (!$this->checkAvailability())
		{
			return null;
		}

		$result = $scheduledMessageService->getList((int)$user->getId(), $chatId, $limit, $cursor);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$scheduledMessages = $result->getScheduledMessages();

		$restFormattedScheduledMessages = array_map(
			static fn(\Bitrix\Im\V2\Message\Scheduled\ScheduledMessage $scheduledMessage)
				=> $scheduledMessage->toRestFormat(),
			$scheduledMessages
		);

		return [
			'scheduledMessages' => $restFormattedScheduledMessages,
			'lastCursor' => $result->getLastCursor()?->toRestFormat(),
		];
	}

	/**
	 * @restMethod im.v2.Chat.Message.ScheduledMessage.delete
	 */
	public function deleteAction(
		\Bitrix\Im\V2\Message\Scheduled\ScheduledMessage $scheduledMessage,
		ScheduledMessageService $scheduledMessageService
	): ?array
	{
		if (!$this->checkAvailability())
		{
			return null;
		}

		$result = $scheduledMessageService->deleteManually($scheduledMessage);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$scheduledMessageId = $result->getScheduledMessageId();

		return $scheduledMessageId !== null ? ['scheduledMessageId' => $scheduledMessageId] : null;
	}

	/**
	 * @restMethod im.v2.Chat.Message.ScheduledMessage.update
	 */
	public function updateAction(
		\Bitrix\Im\V2\Message\Scheduled\ScheduledMessage $scheduledMessage,
		UpdateFields $fields,
		ScheduledMessageService $scheduledMessageService
	): ?array
	{
		if (!$this->checkAvailability())
		{
			return null;
		}

		$result = $scheduledMessageService->update($scheduledMessage, $fields);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$updated = $result->getScheduledMessage();

		return $updated !== null ? $this->toRestFormat($updated) : null;
	}

	/**
	 * @restMethod im.v2.Chat.Message.ScheduledMessage.reschedule
	 */
	public function rescheduleAction(
		\Bitrix\Im\V2\Message\Scheduled\ScheduledMessage $scheduledMessage,
		DateTime $sendAt,
		ScheduledMessageService $scheduledMessageService
	): ?array
	{
		if (!$this->checkAvailability())
		{
			return null;
		}

		$result = $scheduledMessageService->reschedule($scheduledMessage, $sendAt);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$rescheduled = $result->getScheduledMessage();

		return $rescheduled !== null ? $this->toRestFormat($rescheduled) : null;
	}

	private function checkAvailability(): bool
	{
		if (Feature::isDisabled(ScheduledMessages::class))
		{
			$this->addError(new ScheduledMessageError(ScheduledMessageError::NOT_AVAILABLE));

			return false;
		}

		return true;
	}
}
