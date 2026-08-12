<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Type;

enum Errors: string
{
	case requestEmpty = 'REQUEST_EMPTY';
	case requestFail = 'REQUEST_FAIL';
	case requestError = 'REQUEST_ERROR';
	case requestInvalid = 'REQUEST_INVALID';
	case requestNotAllowed = 'REQUEST_NOT_ALLOWED';
	case requestNotAllowedHard = 'REQUEST_NOT_ALLOWED_HARD';
	case requestNotAllowedSoft = 'REQUEST_NOT_ALLOWED_SOFT';
	case requestTimeout = 'REQUEST_TIMEOUT';
}
