<?php

namespace Bitrix\ImOpenLines\V2\Chat;

final class OpenLineEntityData
{
	private array $entityData1;
	private array $entityData2;
	private array $entityData3;
	private array $entityId;

	public function __construct(array $rawEntityData)
	{
		$this->entityData1 = $rawEntityData['entityData1'] ?? [];
		$this->entityData2 = $rawEntityData['entityData2'] ?? [];
		$this->entityData3 = $rawEntityData['entityData3'] ?? [];
		$this->entityId = $rawEntityData['entityId'] ?? [];
	}

	// region entityId
	public function getConnectorId(): ?string
	{
		return $this->entityId['connectorId'] ?? null;
	}

	public function getLineId(): ?int
	{
		return isset($this->entityId['lineId']) ? (int)$this->entityId['lineId'] : null;
	}

	public function getConnectorChatId(): ?int
	{
		return isset($this->entityId['connectorChatId']) ? (int)$this->entityId['connectorChatId'] : null;
	}

	public function getConnectorUserId(): ?int
	{
		return isset($this->entityId['connectorUserId']) ? (int)$this->entityId['connectorUserId'] : null;
	}
	// endregion

	// region entityData1 - CRM & session flags
	public function getCrmEnabledRaw(): ?string
	{
		return $this->entityData1['crmEnabled'] ?? null;
	}

	public function getCrmEntityType(): ?string
	{
		return $this->entityData1['crmEntityType'] ?? null;
	}

	public function getCrmEntityId(): ?int
	{
		return isset($this->entityData1['crmEntityId']) ? (int)$this->entityData1['crmEntityId'] : null;
	}

	public function getSessionId(): ?int
	{
		return isset($this->entityData1['sessionId']) ? (int)$this->entityData1['sessionId'] : null;
	}

	public function isPausedRaw(): ?string
	{
		return $this->entityData1['pause'] ?? null;
	}

	public function isWaitingForActionRaw(): ?string
	{
		return $this->entityData1['waitAction'] ?? null;
	}

	public function getBlockDate(): ?string
	{
		return $this->entityData1['blockDate'] ?? null;
	}

	public function getBlockReason(): ?string
	{
		return $this->entityData1['blockReason'] ?? null;
	}

	public function getDateCreate(): ?string
	{
		return $this->entityData1['dateCreate'] ?? null;
	}

	public function isCrmEnabled(): bool
	{
		return $this->toBool($this->entityData1['crmEnabled'] ?? null);
	}

	public function isPaused(): bool
	{
		return $this->toBool($this->entityData1['pause'] ?? null);
	}

	public function isWaitingForAction(): bool
	{
		return $this->toBool($this->entityData1['waitAction'] ?? null);
	}
	// endregion

	// region entityData2 - CRM entities
	public function getLeadId(): ?int
	{
		return isset($this->entityData2['leadId']) ? (int)$this->entityData2['leadId'] : null;
	}

	public function getCompanyId(): ?int
	{
		return isset($this->entityData2['companyId']) ? (int)$this->entityData2['companyId'] : null;
	}

	public function getContactId(): ?int
	{
		return isset($this->entityData2['contactId']) ? (int)$this->entityData2['contactId'] : null;
	}

	public function getDealId(): ?int
	{
		return isset($this->entityData2['dealId']) ? (int)$this->entityData2['dealId'] : null;
	}
	// endregion

	// region entityData3 - additional flags
	public function isSilentModeRaw(): ?string
	{
		return $this->entityData3['silentMode'] ?? null;
	}

	public function isSilentMode(): bool
	{
		return $this->toBool($this->entityData3['silentMode'] ?? null);
	}
	// endregion

	// region utility
	private function toBool(?string $value): bool
	{
		return $value === 'Y';
	}
	// endregion
}
