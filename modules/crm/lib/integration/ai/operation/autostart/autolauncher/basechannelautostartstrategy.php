<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
use Bitrix\Crm\Integration\AI\Operation\Orchestrator;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use CCrmOwnerType;
use Psr\Log\LoggerInterface;

abstract class BaseChannelAutoStartStrategy
{
	public const OPERATION_ADD = 1;
	public const OPERATION_UPDATE = 2;
	public const OPERATION_COMPLETE = 3;

	protected LoggerInterface $logger;
	protected Orchestrator $orchestrator;
	protected ?ItemIdentifier $nextTarget;
	protected ?int $userId;

	abstract public function run(array $changedFields = []): void;

	public function __construct(readonly int $activityOperation, readonly array $activityFields) {}

	final public function setLogger(LoggerInterface $logger): self
	{
		$this->logger = $logger;

		return $this;
	}

	final public function setOrchestrator(Orchestrator $orchestrator): self
	{
		$this->orchestrator = $orchestrator;

		$this->nextTarget = $this->orchestrator
			->findPossibleTargetByBindings($this->activityFields['BINDINGS'] ?? [])
		;
		$this->userId = $this->nextTarget
			? $this->findAssigned($this->nextTarget)
			: null
		;

		return $this;
	}

	final protected function getFillFieldsSettings(): ?FillFieldsSettings
	{
		return AIManager::isEnabledInGlobalSettings()
			? $this->orchestrator->getFillFieldsSettingsByActivity($this->activityFields)
			: null
		;
	}

	private function findAssigned(ItemIdentifier $target): ?int
	{
		if (!CCrmOwnerType::isUseFactoryBasedApproach($target->getEntityTypeId()))
		{
			return null;
		}

		$factory = Container::getInstance()->getFactory($target->getEntityTypeId());

		return $factory?->getItem($target->getEntityId(), [Item::FIELD_NAME_ASSIGNED])?->getAssignedById();
	}
}
