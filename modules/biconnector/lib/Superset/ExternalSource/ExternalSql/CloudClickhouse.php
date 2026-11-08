<?php

namespace Bitrix\BIConnector\Superset\ExternalSource\ExternalSql;

use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Superset\ExternalSource;
use Bitrix\Main\Localization\Loc;

final class CloudClickhouse implements ExternalSource\Source
{
	public function getCode(): string
	{
		return Type::Clickhouse->value;
	}

	public function getOnClickConnectButtonScript(): string
	{
		return "top.BX.Helper.show('redirect=detail&code=28344102')";
	}

	public function isConnected(): bool
	{
		return false;
	}

	public function isAvailable(): bool
	{
		return true;
	}

	public function getTitle(): string
	{
		return Loc::getMessage('BICONNECTOR_EXTERNAL_SOURCE_CLOUD_CLICKHOUSE_TITLE') ?? '';
	}

	public function getDescription(): string
	{
		return Loc::getMessage('BICONNECTOR_EXTERNAL_SOURCE_CLOUD_CLICKHOUSE_DESCRIPTION') ?? '';
	}

	public function getLogo(): ?string
	{
		return null;
	}
}
