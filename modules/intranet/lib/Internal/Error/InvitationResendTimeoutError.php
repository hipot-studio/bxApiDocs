<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Error;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

final class InvitationResendTimeoutError extends Error
{
	public const CODE = 'invite_resend_timeout';

	private int $retryAfter;

	public function __construct(int $retryAfter)
	{
		$this->retryAfter = $retryAfter;

		parent::__construct(
			self::buildMessage($retryAfter),
			self::CODE,
			[
				'retryAfter' => $retryAfter,
			],
		);
	}

	public function getRetryAfter(): int
	{
		return $this->retryAfter;
	}

	private static function buildMessage(int $retryAfter): string
	{
		return (string)Loc::getMessage(
			'INTRANET_INVITATION_RESEND_TIMEOUT_ERROR',
			[
				'#TIME#' => (string)(int)ceil($retryAfter / 60),
			],
		);
	}
}
