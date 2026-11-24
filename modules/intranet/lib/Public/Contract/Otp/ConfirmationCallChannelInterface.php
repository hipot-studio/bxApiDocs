<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Contract\Otp;

use Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall\AttemptResult;
use Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall\StatusResult;

interface ConfirmationCallChannelInterface
{
	public const SCENARIO_PORTAL_2FA_SETUP = 'portal_2fa_setup';
	public const SCENARIO_PORTAL_2FA_LOGIN = 'portal_2fa_login';

	public const ERROR_UNAVAILABLE = 'unavailable';
	public const ERROR_INVALID_REQUEST = 'invalid_request';
	public const ERROR_TRANSPORT_FAILURE = 'transport_failure';

	public const CONFIRMATION_CALL_ID_PATTERN = '/^[A-Za-z0-9_-]{20}$/D';
	public const PHONE_PATTERN = '/^\+[1-9][0-9]{1,14}$/D';

	public function isAvailable(int $userId, string $scenario): bool;

	public function start(string $phoneE164, int $userId, string $scenario): AttemptResult;

	public function getStatus(string $confirmationCallId, int $userId, string $scenario): StatusResult;
}
