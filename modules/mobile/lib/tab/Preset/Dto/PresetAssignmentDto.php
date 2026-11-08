<?php

namespace Bitrix\Mobile\Tab\Preset\Dto;

use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Tab\Preset\Enum\AssignmentTargetType;

final class PresetAssignmentDto implements \JsonSerializable
{
	public function __construct(
		public readonly ?int $id,
		public readonly int $presetId,
		public readonly int $assignerId,
		public readonly AssignmentTargetType $targetType,
		public readonly int $targetId,
		public readonly int $version,
		public readonly bool $isActive = true,
		public readonly ?DateTime $createdAt = null,
		public readonly ?DateTime $updatedAt = null,
	)
	{
	}

	public static function fromRow(array $row): self
	{
		return new self(
			id: isset($row['ID']) ? (int)$row['ID'] : null,
			presetId: (int)$row['PRESET_ID'],
			assignerId: (int)$row['ASSIGNER_ID'],
			targetType: AssignmentTargetType::from((string)$row['TARGET_TYPE']),
			targetId: (int)$row['TARGET_ID'],
			version: (int)$row['VERSION'],
			isActive: ($row['IS_ACTIVE'] ?? 'Y') === 'Y',
			createdAt: $row['CREATED_AT'] ?? null,
			updatedAt: $row['UPDATED_AT'] ?? null,
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'presetId' => $this->presetId,
			'assignerId' => $this->assignerId,
			'targetType' => $this->targetType->value,
			'targetId' => $this->targetId,
			'version' => $this->version,
			'isActive' => $this->isActive,
		];
	}
}
