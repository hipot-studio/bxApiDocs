<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\Main\Localization\Loc;

final class DashboardFieldAssembler extends EntityLinkFieldAssembler
{
	/** @var array<int, int> external id → internal Bitrix24 dashboard id */
	private array $externalIdToInternalId = [];

	protected function getEntityType(): string
	{
		return 'dashboard';
	}

	protected function getNameKey(): string
	{
		return 'EXTERNAL_DASHBOARD_NAME';
	}

	protected function getIdKey(): string
	{
		return 'EXTERNAL_DASHBOARD_ID';
	}

	protected function getSqlLabFallback(): string
	{
		return (string)Loc::getMessage('BIC_USAGE_STAT_GRID_ROW_DASHBOARD_SOURCE_SQL_LAB');
	}

	public function prepareRows(array $rowList): array
	{
		$this->externalIdToInternalId = $this->loadInternalIds($rowList);

		return parent::prepareRows($rowList);
	}

	protected function prepareColumn($value): string
	{
		if (($value['SOURCE'] ?? '') === 'sql_lab')
		{
			return $this->getSqlLabFallback();
		}

		$name = htmlspecialcharsbx((string)($value['NAME'] ?? ''));
		$externalId = (int)($value['ENTITY_ID'] ?? 0);
		if ($name === '' || $externalId <= 0)
		{
			return $name;
		}

		$internalDashboardId = $this->externalIdToInternalId[$externalId] ?? null;
		if ($internalDashboardId === null)
		{
			return $name;
		}

		$href = htmlspecialcharsbx("/bi/dashboard/detail/{$internalDashboardId}/");

		return '<a class="biconnector-usage-stat-link" href="' . $href . '" target="_blank" rel="noopener">' . $name . '</a>';
	}

	/**
	 * @return array<int, int>
	 */
	private function loadInternalIds(array $rowList): array
	{
		$externalIds = [];
		foreach ($rowList as $row)
		{
			$data = $row['data'] ?? [];
			if (($data['SOURCE'] ?? '') === 'sql_lab')
			{
				continue;
			}

			$externalId = (int)($data[$this->getIdKey()] ?? 0);
			if ($externalId > 0)
			{
				$externalIds[$externalId] = $externalId;
			}
		}

		if (!$externalIds)
		{
			return [];
		}

		$map = [];
		$iterator = SupersetDashboardTable::getList([
			'select' => ['ID', 'EXTERNAL_ID'],
			'filter' => ['=EXTERNAL_ID' => array_values($externalIds)],
		]);
		while ($row = $iterator->fetch())
		{
			$map[(int)$row['EXTERNAL_ID']] = (int)$row['ID'];
		}

		return $map;
	}
}
