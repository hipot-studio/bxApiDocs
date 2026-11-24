<?php

namespace Bitrix\BIConnector\Internal\Repository\Mapper;

use Bitrix\BIConnector\Internal\Entity\RowRule;
use Bitrix\BIConnector\Internal\Model\EO_RowRule;
use Bitrix\BIConnector\Internal\Model\RowRuleTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Web\Json;

class RowRuleMapper
{
	public function convertFromOrm(EO_RowRule $ormModel): RowRule
	{
		$rule = new RowRule(
			(string)$ormModel->getTableCode(),
			(string)$ormModel->getFieldName(),
			$this->decodeValues((string)$ormModel->getValueData()),
			$ormModel->getDescription(),
			$ormModel->getCreatedById(),
			$ormModel->getUpdatedById(),
			$ormModel->getDateCreate(),
			$ormModel->getDateModify(),
		);

		$rule->setId($ormModel->getId());

		return $rule;
	}

	public function convertToOrm(RowRule $entity): EO_RowRule
	{
		$ormModel = $entity->getId()
			? EO_RowRule::wakeUp($entity->getId())
			: RowRuleTable::createObject()
		;

		$ormModel
			->setTableCode($entity->getTableCode())
			->setFieldName($entity->getFieldCode())
			->setValueData($this->encodeValues($entity->getValues()))
			->setDescription($entity->getDescription())
			->setCreatedById($entity->getCreatedById())
			->setUpdatedById($entity->getUpdatedById())
			->setDateCreate($entity->getDateCreate())
			->setDateModify($entity->getDateModify())
		;

		return $ormModel;
	}

	public function encodeValues(array $values): string
	{
		return Json::encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public function decodeValues(string $valueData): array
	{
		try
		{
			$values = Json::decode($valueData);
		}
		catch (ArgumentException)
		{
			return [];
		}

		return is_array($values) ? $values : [];
	}
}
