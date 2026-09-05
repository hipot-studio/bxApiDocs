<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\Main\EventManager;
use Bitrix\Main\Result;

abstract class BaseVersion
{
	abstract public function run(): Result;

	/**
	 * Registers an event handler owned by biconnector unless the very same handler already exists.
	 * Keeps a repeated updater run idempotent.
	 */
	protected function registerHandlerIfNotExists(
		string $fromModuleId,
		string $eventType,
		string $toClass,
		string $toMethod
	): void
	{
		$eventManager = EventManager::getInstance();
		$handlers = $eventManager->findEventHandlers($fromModuleId, $eventType, ['biconnector']);

		foreach ($handlers as $handler)
		{
			if (
				isset($handler['TO_MODULE_ID'], $handler['TO_CLASS'], $handler['TO_METHOD'])
				&& $handler['TO_MODULE_ID'] === 'biconnector'
				&& $handler['TO_CLASS'] === $toClass
				&& $handler['TO_METHOD'] === $toMethod
			)
			{
				return;
			}
		}

		$eventManager->registerEventHandler($fromModuleId, $eventType, 'biconnector', $toClass, $toMethod);
	}
}
