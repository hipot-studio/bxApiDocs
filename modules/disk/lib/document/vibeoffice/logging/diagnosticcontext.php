<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Logging;

use Bitrix\Main\UuidGenerator;

final class DiagnosticContext
{
	private const MAX_DOCUMENT_KEY_BYTES = 128;
	private const DOCUMENT_HASH_DOMAIN = "disk.vibeoffice.document.v1\0";
	private const GENERATION_HASH_DOMAIN = "disk.vibeoffice.generation.v1\0";

	public static function documentCorrelationId(?string $key): ?string
	{
		return self::correlationId($key, self::DOCUMENT_HASH_DOMAIN, 'vod1_');
	}

	public static function generationCorrelationId(?string $generation): ?string
	{
		return self::correlationId($generation, self::GENERATION_HASH_DOMAIN, 'vog1_');
	}

	private static function correlationId(?string $key, string $domain, string $prefix): ?string
	{
		if ($key === null || $key === '' || strlen($key) > self::MAX_DOCUMENT_KEY_BYTES)
		{
			return null;
		}

		try
		{
			return $prefix . hash('sha256', $domain . $key);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	public static function createTraceId(): ?string
	{
		try
		{
			return UuidGenerator::generateV4();
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
