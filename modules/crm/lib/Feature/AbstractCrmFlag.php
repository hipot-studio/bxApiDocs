<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\Common;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Config\Feature\AbstractFlag;

abstract class AbstractCrmFlag extends AbstractFlag
{
	abstract public function getName(): string;

	public function getCategory(): BaseCategory
	{
		return Common::getInstance();
	}

	public function getSort(): int
	{
		return 100;
	}

	public function allowSwitchBySecretLink(): bool
	{
		return true;
	}

	/**
	 * Feature unique id, same value as {@see BaseFeature::getId()} of the legacy contract.
	 */
	protected function getId(): string
	{
		$classParts = explode('\\', $this->getCode());

		return end($classParts);
	}

	/**
	 * Overriding without a parent:: call drops the audit record of the switch.
	 */
	protected function onEnable(): void
	{
		$this->logSwitch('enabled');
	}

	/**
	 * Overriding without a parent:: call drops the audit record of the switch.
	 */
	protected function onDisable(): void
	{
		$this->logSwitch('disabled');
	}

	private function logSwitch(string $action): void
	{
		Container::getInstance()->getLogger('Features')->info(
			$this->getId() . ' ' . $action,
			[
				'userId' => Container::getInstance()->getContext()->getUserId(),
			],
		);
	}
}
