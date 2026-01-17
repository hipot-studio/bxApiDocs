<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Main\Controller\PhoneAuth;

class OtpSigner
{
	public function sign(array $data): string
	{
		return PhoneAuth::signData($data);
	}

	public function signUserId(int $userId): string
	{
		return $this->sign(['userId' => $userId]);
	}

	public function extract($signedData): ?array
	{
		return PhoneAuth::extractData($signedData) ?: null;
	}

	public function extractUserId($signedUserId): ?int
	{
		$userId = (int)($this->extract($signedUserId)['userId'] ?? 0);

		return $userId > 0 ? $userId : null;
	}
}
