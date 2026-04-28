<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Error;

use Bitrix\Main\Error;

class FileError extends Error
{
	public const FILE_EMPTY = 'FILE_EMPTY';
	public const INVALID_CONTENT = 'FILE_INVALID_CONTENT';
	public const FOLDER_ERROR = 'FILE_FOLDER_ERROR';
	public const UPLOAD_FAILED = 'FILE_UPLOAD_FAILED';
	public const SEND_FAILED = 'FILE_SEND_FAILED';
	public const NOT_FOUND = 'FILE_NOT_FOUND';
	public const TOO_LARGE = 'FILE_TOO_LARGE';

	private const MESSAGES = [
		self::FILE_EMPTY => 'File name and content are required',
		self::INVALID_CONTENT => 'Invalid base64 content',
		self::FOLDER_ERROR => 'Could not resolve chat folder',
		self::UPLOAD_FAILED => 'File upload failed',
		self::SEND_FAILED => 'Failed to send file message',
		self::NOT_FOUND => 'File not found',
		self::TOO_LARGE => 'File size exceeds maximum allowed',
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
