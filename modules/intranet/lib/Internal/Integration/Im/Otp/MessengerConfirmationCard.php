<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Im\Otp;

use Bitrix\Intranet\Internal\Entity\Otp\MessengerConfirmationState;
use Bitrix\Intranet\Public\Enum\Otp\MessengerConfirmationStatus;
use Bitrix\Im\Bot;
use Bitrix\Im\Bot\Keyboard;
use Bitrix\Im\Command;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\ImBot\Bot\Marta;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

final class MessengerConfirmationCard
{
	public const COMMAND_CONFIRM = 'otpMessengerConfirm';
	public const COMMAND_DECLINE = 'otpMessengerDecline';

	public const OPTION_COMMANDS = 'messenger_confirmation_commands';

	public const ERROR_DELIVERY = 'delivery_failed';

	public const DATA_MESSAGE_ID = 'messageId';

	private const MODULE_ID = 'intranet';
	private const PUSH_APP_ID = 'Bitrix24';
	private const PUSH_TYPE = 'OTP_MESSENGER_CONFIRMATION';

	public function send(MessengerConfirmationState $state): Result
	{
		$result = new Result();

		if (!Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			return $result->addError($this->createDeliveryError());
		}

		$botId = Marta::getBotIdOrRegister();
		if ($botId <= 0)
		{
			return $result->addError($this->createDeliveryError());
		}

		if (
			!$this->registerCommands($botId)
			|| User::getInstance($state->userId)->getChatWith($botId, true)?->getId() === null
		)
		{
			return $result->addError($this->createDeliveryError());
		}

		$attach = $this->buildAttach($state);
		$keyboard = $this->buildKeyboard($state->requestId, $botId);
		if (!$attach->IsAllowSize() || !$keyboard->isAllowSize())
		{
			return $result->addError($this->createDeliveryError());
		}

		$messageId = (int)\CIMMessage::Add([
			'FROM_USER_ID' => $botId,
			'TO_USER_ID' => $state->userId,
			'MESSAGE' => (string)Loc::getMessage('INTRANET_OTP_MESSENGER_CONFIRMATION_CARD_TITLE'),
			'ATTACH' => $attach,
			'KEYBOARD' => $keyboard,
			'MENU' => null,
			'PARAMS' => [],
			'URL_PREVIEW' => 'N',
			'SKIP_COMMAND' => 'Y',
			'PUSH' => 'N',
		]);

		if ($messageId <= 0)
		{
			return $result->addError($this->createDeliveryError());
		}

		$this->notify($state->userId, $botId);

		return $result->setData([self::DATA_MESSAGE_ID => $messageId]);
	}

	/**
	 * A messenger that cannot answer refuses the press instead of breaking the transition, so every failure
	 * of the lookup reads the same way as a message of someone else.
	 */
	public function isOwnCard(int $messageId, int $userId): bool
	{
		try
		{
			return $this->resolveOwnCard($messageId, $userId);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function applyTerminalView(int $messageId, MessengerConfirmationStatus $status): bool
	{
		$text = $this->getTerminalText($status);
		if ($messageId <= 0 || $text === null || !Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			return false;
		}

		$botId = Marta::getBotIdOrRegister();
		if ($botId <= 0)
		{
			return false;
		}

		return (bool)Bot::updateMessage(['BOT_ID' => $botId], [
			'MESSAGE_ID' => $messageId,
			'KEYBOARD' => 'N',
			'MESSAGE' => $text,
			'URL_PREVIEW' => 'N',
		]);
	}

	public function buildAttach(MessengerConfirmationState $state): \CIMMessageParamAttach
	{
		$context = $state->attemptContext ?? [];
		$user = UserTable::getRow([
			'select' => ['LOGIN'],
			'filter' => ['=ID' => $state->userId],
		]);

		$attach = new \CIMMessageParamAttach(null, \CIMMessageParamAttach::ATTENTION);
		$attach->AddMessage((string)Loc::getMessage('INTRANET_OTP_MESSENGER_CONFIRMATION_CARD_HINT'));
		$attach->AddGrid($this->createGrid([
			'LOGIN' => (string)($user['LOGIN'] ?? ''),
			'IP' => (string)($context['ip'] ?? ''),
			'DEVICE' => (string)($context['device'] ?? ''),
			'BROWSER' => (string)($context['browser'] ?? ''),
			'TIME' => $this->formatMoment(time(), 'd.m.Y H:i', $state->userId),
			'EXPIRES' => $this->formatMoment($state->expiresAt, 'H:i', $state->userId),
		]));

		return $attach;
	}

	public function buildKeyboard(string $requestId, int $botId): Keyboard
	{
		$keyboard = new Keyboard($botId);
		$keyboard->addButton([
			'TEXT' => (string)Loc::getMessage('INTRANET_OTP_MESSENGER_CONFIRMATION_CARD_BUTTON_CONFIRM'),
			'COMMAND' => self::COMMAND_CONFIRM,
			'COMMAND_PARAMS' => $requestId,
			'CONTEXT' => 'ALL',
			'DISPLAY' => 'LINE',
			'BLOCK' => 'N',
		]);
		$keyboard->addButton([
			'TEXT' => (string)Loc::getMessage('INTRANET_OTP_MESSENGER_CONFIRMATION_CARD_BUTTON_DECLINE'),
			'COMMAND' => self::COMMAND_DECLINE,
			'COMMAND_PARAMS' => $requestId,
			'CONTEXT' => 'ALL',
			'DISPLAY' => 'LINE',
			'BLOCK' => 'N',
		]);

		return $keyboard;
	}

	public function getTerminalText(MessengerConfirmationStatus $status): ?string
	{
		$phraseCode = match ($status)
		{
			MessengerConfirmationStatus::Confirmed => 'CONFIRMED',
			MessengerConfirmationStatus::Declined => 'DECLINED',
			MessengerConfirmationStatus::Expired => 'EXPIRED',
			MessengerConfirmationStatus::Cancelled => 'CANCELLED',
			MessengerConfirmationStatus::Pending => null,
		};

		return $phraseCode === null
			? null
			: (string)Loc::getMessage('INTRANET_OTP_MESSENGER_CONFIRMATION_CARD_STATUS_' . $phraseCode);
	}

	private function resolveOwnCard(int $messageId, int $userId): bool
	{
		if ($messageId <= 0 || $userId <= 0 || !Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			return false;
		}

		$botId = Marta::getBotIdOrRegister();
		if ($botId <= 0)
		{
			return false;
		}

		$message = MessageTable::getRow([
			'select' => ['AUTHOR_ID', 'CHAT_ID'],
			'filter' => ['=ID' => $messageId],
		]);
		if ($message === null || (int)($message['AUTHOR_ID'] ?? 0) !== $botId)
		{
			return false;
		}

		$chatId = User::getInstance($userId)->getChatWith($botId, false)?->getId();

		return $chatId !== null && $chatId === (int)($message['CHAT_ID'] ?? 0);
	}

	private function registerCommands(int $botId): bool
	{
		$registered = (string)Option::get(self::MODULE_ID, self::OPTION_COMMANDS, '');
		if ($registered === (string)$botId)
		{
			return true;
		}

		foreach ([self::COMMAND_CONFIRM => 'onConfirm', self::COMMAND_DECLINE => 'onDecline'] as $command => $method)
		{
			$commandId = Command::register([
				'MODULE_ID' => self::MODULE_ID,
				'BOT_ID' => $botId,
				'COMMAND' => $command,
				'HIDDEN' => 'Y',
				'EXTRANET_SUPPORT' => 'Y',
				'CLASS' => MessengerConfirmationCommandHandler::class,
				'METHOD_COMMAND_ADD' => $method,
			]);

			if (!$commandId)
			{
				return false;
			}
		}

		Option::set(self::MODULE_ID, self::OPTION_COMMANDS, (string)$botId);

		return true;
	}

	private function notify(int $userId, int $botId): void
	{
		try
		{
			(new \CPushManager())->sendMessage([[
				'USER_ID' => $userId,
				'APP_ID' => self::PUSH_APP_ID,
				'TITLE' => (string)Loc::getMessage('INTRANET_OTP_MESSENGER_CONFIRMATION_CARD_TITLE'),
				'MESSAGE' => (string)Loc::getMessage('INTRANET_OTP_MESSENGER_CONFIRMATION_CARD_PUSH_MESSAGE'),
				'PARAMS' => [
					'type' => self::PUSH_TYPE,
					'dialogId' => $botId,
				],
			]]);
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * @param array<string, string> $values
	 */
	private function createGrid(array $values): array
	{
		$grid = [];

		foreach ($values as $phraseCode => $value)
		{
			// A dash reads as a detail of the attempt; a detail the portal cannot determine is left out.
			if ($value === '')
			{
				continue;
			}

			$grid[] = [
				'DISPLAY' => 'LINE',
				'NAME' => (string)Loc::getMessage('INTRANET_OTP_MESSENGER_CONFIRMATION_CARD_FIELD_' . $phraseCode),
				'VALUE' => $value,
			];
		}

		return $grid;
	}

	private function formatMoment(int $timestamp, string $format, int $userId): string
	{
		$moment = DateTime::createFromTimestamp($timestamp);
		$moment->setDefaultTimeZone();

		// The card is read by the owner of the pre-authentication attempt.
		$offset = (int)\CTimeZone::GetOffset($userId, false, $moment);
		if ($offset !== 0)
		{
			$moment->add(($offset < 0 ? '-' : '') . 'PT' . abs($offset) . 'S');
		}

		return $moment->format($format);
	}

	private function createDeliveryError(): Error
	{
		return new Error(
			(string)Loc::getMessage('INTRANET_OTP_MESSENGER_CONFIRMATION_CARD_ERROR_DELIVERY'),
			self::ERROR_DELIVERY,
		);
	}
}
