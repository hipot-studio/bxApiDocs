<?php

namespace Bitrix\BIConnector\DataSourceConnector;

use Bitrix\Main\Result;

final class ConnectorDataResult extends Result
{
	/**
	 * Initialized and not merely typed: an unsuccessful result carries no connector data at all, and a
	 * typed property left unassigned answers a read with an \Error instead of null.
	 */
	private ?ConnectorDto $connectorData = null;

	public function setConnectorData(ConnectorDto $connectorData): void
	{
		$this->connectorData = $connectorData;
	}

	public function getConnectorData(): ?ConnectorDto
	{
		return $this->connectorData;
	}
}
