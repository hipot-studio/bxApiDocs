<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Main\Context;
use Bitrix\Main\HttpRequest;

final class OriginTokenReader
{
	public const HEADER_NAME = 'X-Vibecode-Origin';

	private const MAX_TOKEN_LENGTH = 24576;

	public function read(): string
	{
		try
		{
			$request = Context::getCurrent()?->getRequest();
			if (!$request instanceof HttpRequest)
			{
				return '';
			}

			$token = trim((string)($request->getHeader(self::HEADER_NAME) ?? ''));

			return strlen($token) <= self::MAX_TOKEN_LENGTH ? $token : '';
		}
		catch (\Throwable)
		{
			return '';
		}
	}
}
