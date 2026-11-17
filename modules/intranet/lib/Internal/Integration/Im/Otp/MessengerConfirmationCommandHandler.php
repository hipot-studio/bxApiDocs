<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Im\Otp;

use Bitrix\Intranet\Internal\Entity\Otp\MessengerConfirmationState;
use Bitrix\Intranet\Internal\Service\Otp\MessengerConfirmationCoordinator;
use Bitrix\Intranet\Public\Dto\Otp\MessengerConfirmation\Status;
use Bitrix\Intranet\Public\Enum\Otp\MessengerConfirmationStatus;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Rest\SessionAuth\Auth;

final class MessengerConfirmationCommandHandler
{
	private const KEY_USER_ID = 'userId';
	private const KEY_REQUEST_ID = 'requestId';
	private const COORDINATOR_SERVICE = 'intranet.service.otp.messenger_confirmation';
	private const MESSAGE_TYPE_PRIVATE = 'P';

	private MessengerConfirmationCard $card;

	public function __construct()
	{
		$this->card = new MessengerConfirmationCard();
	}

	public static function onConfirm($messageId, $messageFields): bool
	{
		return (new self())->confirm((int)$messageId, (array)$messageFields);
	}

	public static function onDecline($messageId, $messageFields): bool
	{
		return (new self())->decline((int)$messageId, (array)$messageFields);
	}

	public function confirm(int $messageId, array $messageFields): bool
	{
		return $this->handle($messageId, $messageFields, MessengerConfirmationStatus::Confirmed);
	}

	public function decline(int $messageId, array $messageFields): bool
	{
		return $this->handle($messageId, $messageFields, MessengerConfirmationStatus::Declined);
	}

	/**
	 * Both buttons end the request, so the card is left telling how it ended and taking no press at all.
	 */
	private function handle(int $messageId, array $messageFields, MessengerConfirmationStatus $target): bool
	{
		$press = $this->parsePress($messageFields);
		if ($press === null || !self::isPressedInUserSession())
		{
			return false;
		}

		$status = $this->readStatus($this->applyTransition($press, $messageId, $target));
		if ($status === null || !$status->status->isTerminal())
		{
			return false;
		}

		return $this->card->applyTerminalView($messageId, $status->status);
	}

	/**
	 * @param array{userId: int, requestId: string} $press
	 */
	private function applyTransition(array $press, int $messageId, MessengerConfirmationStatus $target): Result
	{
		$coordinator = ServiceLocator::getInstance()->get(self::COORDINATOR_SERVICE);
		$requestId = $press[self::KEY_REQUEST_ID];
		$userId = $press[self::KEY_USER_ID];

		return $target === MessengerConfirmationStatus::Confirmed
			? $coordinator->confirm($requestId, $userId, $messageId)
			: $coordinator->decline($requestId, $userId, $messageId);
	}

	/**
	 * @return array{userId: int, requestId: string}|null
	 */
	public function parsePress(array $messageFields): ?array
	{
		if (($messageFields['MESSAGE_TYPE'] ?? null) !== self::MESSAGE_TYPE_PRIVATE)
		{
			return null;
		}

		$userId = (int)($messageFields['FROM_USER_ID'] ?? 0);
		$requestId = trim((string)($messageFields['COMMAND_PARAMS'] ?? ''));

		if ($userId <= 0 || preg_match(MessengerConfirmationState::REQUEST_ID_PATTERN, $requestId) !== 1)
		{
			return null;
		}

		return [
			self::KEY_USER_ID => $userId,
			self::KEY_REQUEST_ID => $requestId,
		];
	}

	/**
	 * An OAuth application or a webhook holding the user's token must not pass the second factor.
	 */
	private static function isPressedInUserSession(): bool
	{
		if (!Loader::includeModule('rest'))
		{
			return false;
		}

		return \CRestServer::instance()?->getAuthType() === Auth::AUTH_TYPE;
	}

	private function readStatus(Result $result): ?Status
	{
		if (!$result->isSuccess())
		{
			return null;
		}

		$status = $result->getData()[MessengerConfirmationCoordinator::DATA_STATUS] ?? null;

		return $status instanceof Status ? $status : null;
	}
}
