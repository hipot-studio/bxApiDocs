<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Workflow;

use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Dto\DocumentDto;
use Bitrix\Bizproc\Starter\Dto\EventDto;
use Bitrix\Bizproc\Starter\Dto\MetaDataDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Starter;

class StarterService
{
	public function __construct()
	{}

	public function getStarterByScenario(Scenario $scenario): ?Starter
	{
		if (Starter::isEnabled())
		{
			return Starter::getByScenario($scenario);
		}

		return null;
	}

	/**
	 * Get starter for manual Document scenario.
	 *
	 * @param array $templateIds
	 * @param ContextDto $context
	 * @param DocumentDto $document
	 * @param int $userId
	 * @param array|string $parameters
	 * @param MetaDataDto|null $metaData
	 *
	 * @return Starter|null
	 */
	public function getStarterForManualDocumentScenario(
		array $templateIds,
		ContextDto $context,
		DocumentDto $document,
		int $userId = 0,
		array | string $parameters = [],
		?MetaDataDto $metaData = null,
	): ?Starter
	{
		$starter = $this->getStarterByScenario(Scenario::onManual);
		if (!$starter)
		{
			return null;
		}

		$starter
			->setDocument($document)
			->setTemplateIds($templateIds)
			->setContext($context)
			->setUser($userId)
			->setParameters($parameters);

		if ($metaData)
		{
			$starter->setMetaData($metaData);
		}

		return $starter;
	}

	/**
	 * Get starter for manual Event scenario.
	 *
	 * @param array $templateIds
	 * @param ContextDto $context
	 * @param EventDto[] $events
	 * @param int $userId
	 * @param array|string $parameters
	 * @param MetaDataDto|null $metaData
	 *
	 * @return Starter|null
	 */
	public function getStarterForManualEventScenario(
		array $templateIds,
		ContextDto $context,
		array $events,
		int $userId = 0,
		array | string $parameters = [],
		?MetaDataDto $metaData = null,
	): ?Starter
	{
		$starter = $this->getStarterByScenario(Scenario::onEvent);
		if (!$starter)
		{
			return null;
		}

		$starter->setContext($context);
		foreach ($events as $event)
		{
			if (!($event instanceof EventDto))
			{
				continue;
			}

			$starter->addEvent(
				$event->code,
				$event->documents,
				$event->parameters,
				$event->eventType,
				$event->userId,
			);
		}

		$starter
			->setTemplateIds($templateIds)
			->setUser($userId)
			->setParameters($parameters);

		if ($metaData)
		{
			$starter->setMetaData($metaData);
		}

		return $starter;
	}

	/**
	 * @param ContextDto $context
	 * @param EventDto[] $events
	 *
	 * @return Starter|null
	 */
	public function getStarterByEventScenario(
		ContextDto $context,
		array $events,
	): ?Starter
	{
		$starter = $this->getStarterByScenario(Scenario::onEvent);
		if (!$starter)
		{
			return null;
		}

		$starter->setContext($context);
		foreach ($events as $event)
		{
			$starter
				->addEvent(
					$event->code,
					$event->documents,
					$event->parameters,
					$event->eventType,
					$event->userId,
				)
			;
		}

		return $starter;
	}
}
