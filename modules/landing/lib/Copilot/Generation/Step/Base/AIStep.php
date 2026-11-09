<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Base;

use Bitrix\Landing\Copilot\Connector\AI\IConnector;
use Bitrix\Landing\Copilot\Connector\AI\UserAwareConnectorInterface;
use Bitrix\Landing\Copilot\Generation;

abstract class AIStep extends BaseStep
{
	protected IConnector $connector;

	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * Return class name of AI connector
	 * Should be overwritten in child classes.
	 * @return string
	 */
	abstract public static function getConnectorClass(): string;

	protected function initializeConnector(): void
	{
		$connectorClass = static::getConnectorClass();
		if (class_exists($connectorClass))
		{
			$this->connector = new $connectorClass();
		}
	}

	/**
	 * @inheritdoc
	 */
	public function init(Generation $generation, int $stepId): static
	{
		parent::init($generation, $stepId);

		// a generation goes on in queue and agent hits without a session: requests belong to its author
		if (($this->connector ?? null) instanceof UserAwareConnectorInterface)
		{
			$this->connector->setUserId(max(0, $this->generation->getAuthorId()));
		}

		return $this;
	}

	public function execute(): bool
	{
		return parent::execute() && isset($this->connector);
	}
}
