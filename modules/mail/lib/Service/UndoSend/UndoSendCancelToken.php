<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\UndoSend;

use Bitrix\Mail\Service\ScheduledMessage\ScheduledMessageService;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Type\DateTime;

/**
 * The bearer of the cancellation, issued to the author and spent by an action that runs without a
 * session - the reason is written above the action list of the controller. Three properties make that
 * safe: the payload is signed, so neither the user nor the operation in it can be swapped; it expires
 * with the cancellation window, so a leaked token is worthless within seconds; and it names the
 * revision of the operation, which a successful cancellation bumps - so the token is good for one
 * cancellation and a replay of it stops on the revision check.
 */
class UndoSendCancelToken
{
	public const ERROR_INVALID_TOKEN = 'invalid_cancel_token';

	private const TOKEN_VERSION = 1;
	private const TOKEN_SALT = 'mail.undo_send.cancel';
	private const MAX_TOKEN_LENGTH = 512;

	public function __construct(private readonly Signer $signer = new Signer())
	{
	}

	public function issue(int $userId, int $operationId, int $revision, DateTime $cancelUntil): string
	{
		$payload = implode(':', [
			self::TOKEN_VERSION,
			$userId,
			$operationId,
			$revision,
			$cancelUntil->getTimestamp(),
		]);

		return $this->signer->sign($payload, self::TOKEN_SALT);
	}

	public function verify(string $token, ?DateTime $now = null): Result
	{
		if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH)
		{
			return $this->invalidTokenResult();
		}

		try
		{
			$payload = $this->signer->unsign($token, self::TOKEN_SALT);
		}
		catch (\Throwable)
		{
			return $this->invalidTokenResult();
		}

		$parts = explode(':', $payload);
		if (
			count($parts) !== 5
			|| array_filter($parts, static fn (string $part): bool => preg_match('/^[0-9]+$/D', $part) !== 1)
		)
		{
			return $this->invalidTokenResult();
		}

		[$version, $userId, $operationId, $revision, $cancelUntil] = array_map('intval', $parts);
		if (
			$version !== self::TOKEN_VERSION
			|| $userId <= 0
			|| $operationId <= 0
			|| $revision <= 0
			|| $cancelUntil <= 0
		)
		{
			return $this->invalidTokenResult();
		}

		$now ??= new DateTime();
		if ($cancelUntil < $now->getTimestamp())
		{
			return (new Result())->addError(new Error(
				'Cancellation window has expired.',
				ScheduledMessageService::ERROR_CANCEL_WINDOW_EXPIRED,
			));
		}

		return (new Result())->setData([
			'userId' => $userId,
			'operationId' => $operationId,
			'revision' => $revision,
		]);
	}

	private function invalidTokenResult(): Result
	{
		return (new Result())->addError(new Error('Cancellation token is invalid.', self::ERROR_INVALID_TOKEN));
	}
}
