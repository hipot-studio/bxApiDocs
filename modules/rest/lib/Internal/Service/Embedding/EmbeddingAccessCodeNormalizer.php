<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Embedding;

use Bitrix\Rest\Internal\Model\EmbeddingAccessTable;

final class EmbeddingAccessCodeNormalizer
{
	public const ACCESS_CODE_AUTHORIZED = 'AU';
	private const ACCESS_CODE_ALL = 'UA';

	public static function normalizeMap(array $accessCodesByEmbeddingId): array
	{
		$normalized = [];

		foreach ($accessCodesByEmbeddingId as $embeddingId => $accessCodes)
		{
			$normalized[$embeddingId] = self::normalize($accessCodes);
		}

		return $normalized;
	}

	public static function normalize(array $accessCodes): array
	{
		$codes = [];

		foreach ($accessCodes as $accessCode)
		{
			if (!is_string($accessCode))
			{
				continue;
			}

			$code = trim($accessCode);
			if (empty($code) || mb_strlen($code) > EmbeddingAccessTable::ACCESS_CODE_MAX_LENGTH)
			{
				continue;
			}

			if ($code === self::ACCESS_CODE_ALL)
			{
				$code = self::ACCESS_CODE_AUTHORIZED;
			}

			$codes[$code] = $code;
		}

		return array_values($codes);
	}
}
