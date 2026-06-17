<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher;

use Bitrix\Crm\Copilot\Pipeline\TargetResolver;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
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

	protected ?int $userId;
	protected LoggerInterface $logger;
	protected TargetResolver $targetResolver;
	protected ?ItemIdentifier $nextTarget;

	/**
	 * Method for running the strategy.
	 * Should contain the logic of checking the conditions for autostart and starting the operation if conditions are met.
	 *
	 * @param array $changedFields
	 *
	 * @return void
	 */
	abstract public function run(array $changedFields = []): void;

	public function __construct(readonly int $activityOperation, readonly array $activityFields) {}

	final public function setLogger(LoggerInterface $logger): self
	{
		$this->logger = $logger;

		return $this;
	}

	final public function setTargetResolver(TargetResolver $targetResolver): self
	{
		$this->targetResolver = $targetResolver;
		$this->nextTarget = $this->targetResolver->findTargetByBindings($this->activityFields['BINDINGS'] ?? []);
		$this->userId = $this->nextTarget
			? $this->findAssigned($this->nextTarget)
			: null
		;

		return $this;
	}

	final protected function getFillFieldsSettings(): ?FillFieldsSettings
	{
		return (
			AIManager::isEnabledInGlobalSettings()
			|| AIManager::isEnabledInGlobalSettings(GlobalSetting::AnalyzeCommunication)
			|| AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize)
		)
			? $this->getFillFieldsSettingsByActivity()
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

	private function getFillFieldsSettingsByActivity(): ?FillFieldsSettings
	{
		if (!$this->nextTarget)
		{
			return null;
		}

		return FillFieldsSettings::get($this->nextTarget->getEntityTypeId(), $this->nextTarget->getCategoryId());
	}
}
