<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\Main\Type\DateTime;

final readonly class StructurePositionChange
{
	/**
	 * @param array<int, string> $previousNodeNames Names by node id.
	 * @param array<int, string> $newNodeNames Names by node id.
	 */
	public function __construct(
		public int $userId,
		public StructurePositionChangeType $type,
		public array $previousNodeNames,
		public array $newNodeNames,
		public ?NodeMemberRole $previousRole,
		public ?NodeMemberRole $newRole,
	)
	{
	}

	public function toEventFields(): array
	{
		return [
			StructurePositionChangedTrigger::FIELD_USER_ID => $this->userId,
			StructurePositionChangedTrigger::FIELD_CHANGE_TYPE => $this->type->value,
			StructurePositionChangedTrigger::FIELD_PREVIOUS_NODE_IDS => array_keys($this->previousNodeNames),
			StructurePositionChangedTrigger::FIELD_PREVIOUS_NODE_NAMES => array_values($this->previousNodeNames),
			StructurePositionChangedTrigger::FIELD_NEW_NODE_IDS => array_keys($this->newNodeNames),
			StructurePositionChangedTrigger::FIELD_NEW_NODE_NAMES => array_values($this->newNodeNames),
			StructurePositionChangedTrigger::FIELD_PREVIOUS_ROLE => $this->previousRole?->value ?? '',
			StructurePositionChangedTrigger::FIELD_NEW_ROLE => $this->newRole?->value ?? '',
			StructurePositionChangedTrigger::FIELD_EVENT_DATE_TIME => (new DateTime())->format(DateTime::getFormat()),
		];
	}
}
