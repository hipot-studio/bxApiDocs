<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Error;

use Bitrix\Main\Error;

class BotError extends Error
{
	public const BOT_NOT_FOUND = 'BOT_NOT_FOUND';
	public const BOT_OWNERSHIP_ERROR = 'BOT_OWNERSHIP_ERROR';
	public const BOT_ID_REQUIRED = 'BOT_ID_REQUIRED';
	public const CODE_ALREADY_TAKEN = 'BOT_CODE_ALREADY_TAKEN';
	public const LIMIT_EXCEEDED = 'BOT_LIMIT_EXCEEDED';
	public const REGISTER_FAILED = 'BOT_REGISTER_FAILED';
	public const UPDATE_FAILED = 'BOT_UPDATE_FAILED';
	public const UNREGISTER_FAILED = 'BOT_UNREGISTER_FAILED';
	public const INVALID_CALLBACK = 'BOT_INVALID_CALLBACK';
	public const CODE_REQUIRED = 'BOT_CODE_REQUIRED';
	public const PROPERTIES_REQUIRED = 'BOT_PROPERTIES_REQUIRED';
	public const INVALID_TYPE = 'BOT_INVALID_TYPE';
	public const INVALID_EVENT_MODE = 'BOT_INVALID_EVENT_MODE';
	public const WEBHOOK_URL_REQUIRED = 'BOT_WEBHOOK_URL_REQUIRED';
	public const AVATAR_INCORRECT_TYPE = 'BOT_AVATAR_INCORRECT_TYPE';
	public const AVATAR_INCORRECT_SIZE = 'BOT_AVATAR_INCORRECT_SIZE';

	private const MESSAGES = [
		self::BOT_NOT_FOUND => 'Bot not found',
		self::BOT_OWNERSHIP_ERROR => 'Bot does not belong to this application',
		self::BOT_ID_REQUIRED => 'Bot ID is required',
		self::CODE_ALREADY_TAKEN => 'Bot with this code already exists',
		self::LIMIT_EXCEEDED => 'Bot registration limit exceeded',
		self::REGISTER_FAILED => 'Bot registration failed',
		self::UPDATE_FAILED => 'Bot update failed',
		self::UNREGISTER_FAILED => 'Bot unregister failed',
		self::INVALID_CALLBACK => 'Invalid callback URL',
		self::CODE_REQUIRED => 'Bot code is required',
		self::PROPERTIES_REQUIRED => 'Bot properties are required',
		self::INVALID_TYPE => 'Invalid bot type',
		self::INVALID_EVENT_MODE => 'Invalid event mode',
		self::WEBHOOK_URL_REQUIRED => 'Webhook URL is required for webhook event mode',
		self::AVATAR_INCORRECT_TYPE => 'Avatar must be an image file',
		self::AVATAR_INCORRECT_SIZE => 'Avatar image exceeds maximum dimensions (5000x5000)',
	];

	public function __construct(string $code, string $message = '')
	{
		if ($message === '')
		{
			$message = self::MESSAGES[$code] ?? $code;
		}

		parent::__construct($message, $code);
	}
}
