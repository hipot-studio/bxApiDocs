<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Superset\Internal\Services\SelfHostedServerRuntimeService;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;
use Bitrix\Superset\Public\Support\ServerDtoFactory;

final class SelfHostedServerProvider
{
	public static function findReference(): ?ServerReferenceDto
	{
		$server = self::getRuntimeService()->find();

		return $server ? (new ServerDtoFactory())->createReference($server) : null;
	}

	private static function getRuntimeService(): SelfHostedServerRuntimeService
	{
		return new SelfHostedServerRuntimeService();
	}
}
