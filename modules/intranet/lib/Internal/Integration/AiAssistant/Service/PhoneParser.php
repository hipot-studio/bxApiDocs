<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Service;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Entity\Type\Phone;

class PhoneParser
{
	public static function parse(?string $phoneNumber, string $valueName, bool $required = false): ?string
	{
		$phoneNumber = StringParser::parse($phoneNumber, $valueName, $required);

		if ($phoneNumber !== null)
		{
			$phone = new Phone($phoneNumber);
			if (!$phone->isValid())
			{
				throw new McpException("Parameter \"{$valueName}\" must be a valid phone number.");
			}

			$phoneNumber = $phone->defaultFormat();
		}

		return $phoneNumber;
	}
}
