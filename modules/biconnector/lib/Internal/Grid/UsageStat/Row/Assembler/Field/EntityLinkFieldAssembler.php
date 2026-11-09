<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\Main\Grid\Row\FieldAssembler;

abstract class EntityLinkFieldAssembler extends FieldAssembler
{
	private ?bool $canOpenEntity = null;

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
		if ($name === '' || $entityId <= 0 || !$this->canOpenEntity())
		{
			return $name;
		}

		$onclick = 'BX.BIConnector.UsageStatGridManager.Instance.openElement(\''
			. $this->getEntityType()
			. '\', '
			. $entityId
			. ');'
		;

		return '<button type="button" class="biconnector-usage-stat-link" data-bic-focus-role="entity-link"'
			. ' onclick="' . htmlspecialcharsbx($onclick) . '">'
			. $name
			. '</button>'
		;
	}

	private function canOpenEntity(): bool
	{
		$this->canOpenEntity ??= AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT);

		return $this->canOpenEntity;
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
