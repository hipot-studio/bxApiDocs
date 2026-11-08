<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Embedding;

use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Entity\Application\AppAttributeCode;

class AppEmbeddingAccessCodeResolver
{
	private const ACCESS_CODE_USER_PREFIX = 'U';
	private const LIST_SEPARATOR = ',';

	public function resolve(App $app): array
	{
		$codes = $this->getAccessCodes($app);
		$ownerUserId = $app->getAttributeValue(AppAttributeCode::OwnerUserId->value);

		if ($ownerUserId === null)
		{
			return empty($codes) ? [EmbeddingAccessCodeNormalizer::ACCESS_CODE_AUTHORIZED] : $codes;
		}

		$codes[] = self::ACCESS_CODE_USER_PREFIX . (int)$ownerUserId;

		return $codes;
	}

	private function getAccessCodes(App $app): array
	{
		$access = $app->getAccess();

		return empty($access) ? [] : explode(self::LIST_SEPARATOR, $access);
	}
}
