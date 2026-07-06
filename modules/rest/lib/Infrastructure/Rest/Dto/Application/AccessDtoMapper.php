<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Dto\Application;

use Bitrix\Rest\Internal\Entity\Application\App;

class AccessDtoMapper
{
	public function toDto(App $app): AccessDto
	{
		$dto = new AccessDto();
		$dto->clientId = $app->getClientId();

		$access = $app->getAccess();
		$codes = empty($access) ? [] : explode(',', $access);
		$dto->codes = $codes;

		$dto->codesDetails = $this->resolveCodeDetails($codes);

		return $dto;
	}

	private function resolveCodeDetails(array $codes): array
	{
		if (empty($codes))
		{
			return [];
		}

		$access = new \CAccess();
		$accessNames = $access->getNames($codes);

		$details = [];
		foreach ($codes as $code)
		{
			$details[] = [
				'code' => $code,
				'provider' => $accessNames[$code]['provider_id'] ?? null,
				'name' => $accessNames[$code]['name'] ?? $code,
			];
		}

		return $details;
	}
}
