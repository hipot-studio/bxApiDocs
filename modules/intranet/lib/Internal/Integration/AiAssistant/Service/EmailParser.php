<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Service;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Main\Validation\Validator\EmailValidator;

class EmailParser
{
	private static ?EmailValidator $emailValidator = null;

	public static function parse(?string $email, string $valueName, bool $required = false): ?string
	{
		$email = StringParser::parse($email, $valueName, $required);

		if (
			$email !== null
			&& !self::getEmailValidator()
				->validate($email)
				->isSuccess()
		)
		{
			throw new McpException("Parameter \"{$valueName}\" must be a valid email.");
		}

		return $email;
	}

	private static function getEmailValidator(): EmailValidator
	{
		if (self::$emailValidator === null)
		{
			self::$emailValidator = new EmailValidator();
		}

		return self::$emailValidator;
	}
}
