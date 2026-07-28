<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;

abstract class EntityLinkFieldAssembler extends FieldAssembler
{
	abstract protected function getEntityType(): string;

	abstract protected function getNameKey(): string;

	abstract protected function getIdKey(): string;

	protected function getSqlLabFallback(): string
	{
		return '';
	}

	protected function prepareColumn($value): string
	{
		$source = $value['SOURCE'] ?? '';
		if ($source === 'sql_lab')
		{
			return $this->getSqlLabFallback();
		}

		$name = htmlspecialcharsbx((string)($value['NAME'] ?? ''));

		$entityId = (int)($value['ENTITY_ID'] ?? 0);
		if ($name === '' || $entityId <= 0)
		{
			return $name;
		}

		$onclick = 'BX.BIConnector.UsageStatGridManager.Instance.openElement(\'' . $this->getEntityType() . '\', ' . $entityId . '); return false;';

		return '<a class="biconnector-usage-stat-link" onclick="' . htmlspecialcharsbx($onclick) . '">' . $name . '</a>';
	}

	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$data = $row['data'] ?? [];
		$value = [
			'SOURCE' => $data['SOURCE'] ?? '',
			'NAME' => $data[$this->getNameKey()] ?? '',
			'ENTITY_ID' => $data[$this->getIdKey()] ?? 0,
		];

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['data'][$columnId] = $value;
		}

		return parent::prepareRow($row);
	}
}
