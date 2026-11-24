<?php

namespace Bitrix\BIConnector\Integration\Superset;

use Bitrix\BIConnector\Superset\Config\StoredOptionReader;
use Bitrix\Main\Config\Option;

class SupersetStatusOptionContainer
{
	private const SUPERSET_STATUS_OPTION_NAME = 'superset_status';

	public function set(string $status): void
	{
		Option::set('biconnector', static::SUPERSET_STATUS_OPTION_NAME, $status);
	}

	public function get(): string
	{
		return Option::get('biconnector', static::SUPERSET_STATUS_OPTION_NAME, SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS);
	}

	public function getStored(): string
	{
		return StoredOptionReader::read(
			'biconnector',
			self::SUPERSET_STATUS_OPTION_NAME,
			SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS,
		);
	}
}