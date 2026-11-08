<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

use Bitrix\Crm\Attribute\Entity\FieldAttributeTable;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class OrmRequirementStorageGateway implements StorageGatewayInterface
{
	public function selectIds(StorageKey $key): array
	{
		$rows = FieldAttributeTable::query()
			->setSelect(['ID'])
			->where('ENTITY_TYPE_ID', $key->getEntityTypeId())
			->where('ENTITY_SCOPE', $key->getEntityScope())
			->where('TYPE_ID', $key->getTypeId())
			->where('FIELD_NAME', $key->getFieldName())
			->where('IS_CUSTOM_FIELD', $key->isCustomField() ? 'Y' : 'N')
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	public function delete(int $id): Result
	{
		return FieldAttributeTable::delete($id);
	}

	public function add(
		StorageKey $key,
		int $phaseGroupTypeId,
		string $startPhaseId,
		string $finishPhaseId,
	): Result
	{
		return FieldAttributeTable::add([
			'ENTITY_TYPE_ID' => $key->getEntityTypeId(),
			'ENTITY_SCOPE' => $key->getEntityScope(),
			'TYPE_ID' => $key->getTypeId(),
			'FIELD_NAME' => $key->getFieldName(),
			'CREATED_TIME' => new DateTime(),
			'START_PHASE' => $startPhaseId,
			'FINISH_PHASE' => $finishPhaseId,
			'PHASE_GROUP_TYPE_ID' => $phaseGroupTypeId,
			'IS_CUSTOM_FIELD' => $key->isCustomField() ? 'Y' : 'N',
		]);
	}
}
