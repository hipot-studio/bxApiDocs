<?php

namespace Bitrix\BIConnector\Superset\ExternalSource\ExternalSql;

use Bitrix\BIConnector\ExternalSource\SourceManager;
use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Superset\ExternalSource;
use Bitrix\Main\Localization\Loc;

final class Mysql implements ExternalSource\Source
{
	public function __construct(
		protected bool $isConnected
	)
	{}

	public function getCode(): string
	{
		return Type::Mysql->value;
	}

	public function getOnClickConnectButtonScript(): string
	{
		if (!$this->isAvailable())
		{
			return '(new BX.UI.FeaturePromoter({ code: \'limit_v2_bi_constructor_external_sql_dataset\' })).show()';
		}

		$link = '/bitrix/components/bitrix/biconnector.externalconnection/slider.php?connectorType=' . $this->getCode();

		return "BX.SidePanel.Instance.open('{$link}', {width: 564, cacheable: false})";
	}

	public function isConnected(): bool
	{
		return $this->isConnected;
	}

	public function isAvailable(): bool
	{
		return SourceManager::isExternalSqlConnectionsAvailable();
	}

	public function getTitle(): string
	{
		return Loc::getMessage('BICONNECTOR_EXTERNAL_SOURCE_MYSQL_TITLE');
	}

	public function getDescription(): string
	{
		return Loc::getMessage('BICONNECTOR_EXTERNAL_SOURCE_MYSQL_DESCRIPTION');
	}

	public function getLogo(): ?string
	{
		return null;
	}
}
