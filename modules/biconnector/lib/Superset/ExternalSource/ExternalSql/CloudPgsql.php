<?php

namespace Bitrix\BIConnector\Superset\ExternalSource\ExternalSql;

use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Superset\ExternalSource;
use Bitrix\Main\Localization\Loc;

final class CloudPgsql implements ExternalSource\Source
{
	public function getCode(): string
	{
		return Type::Pgsql->value;
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
		return Loc::getMessage('BICONNECTOR_EXTERNAL_SOURCE_CLOUD_PGSQL_TITLE');
	}

	public function getDescription(): string
	{
		return Loc::getMessage('BICONNECTOR_EXTERNAL_SOURCE_CLOUD_PGSQL_DESCRIPTION');
	}

	public function getLogo(): ?string
	{
		return null;
	}
}
