<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Public\Service\Document\InspectorService;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Dto\DocumentDto;
use Bitrix\Bizproc\Starter\Dto\MetaDataDto;
use Bitrix\Bizproc\Starter\Dto\StarterDto;
use Bitrix\Bizproc\Starter\Dto\StarterConfigDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Result\StartResult;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\ModuleManager;

final class Starter
{
	private ?ProcessStarter $processStarter;
	private ?AutomationStarter $automationStarter;

	public static function isEnabled(): bool
	{
		return Option::get('bizproc', 'enable_starter', 'Y') === 'Y';
	}

	public static function getByScenario(Scenario $scenario): Starter
	{
		$processDto = new StarterConfigDto(scenario: $scenario);
		$automationDto = new StarterConfigDto(scenario: $scenario, validateParameters: false, checkConstants: false);

		return match ($scenario)
		{
			Scenario::onDocumentInnerAdd,
			Scenario::onDocumentInnerUpdate
				=> new self(
					new StarterDto(automation: $automationDto),
				),
			Scenario::onManual => new self(
				new StarterDto(process: $processDto),
			),
			Scenario::onDocumentAdd,
			Scenario::onDocumentUpdate,
				=> new self(
					new StarterDto(process: $processDto, automation: $automationDto)
				),
			Scenario::onEvent => new self(
				new StarterDto(process: $automationDto, automation: $automationDto)
			),
			Scenario::onScript => new self(
				new StarterDto(automation: $automationDto)
			),
			default => new self(new StarterDto())
		};
	}

	public function __construct(StarterDto $dto)
	{
		$this->processStarter = $dto->process ? new ProcessStarter($dto->process) : null;
		$this->automationStarter = $dto->automation ? new AutomationStarter($dto->automation) : null;
	}

	public function setDocument(DocumentDto $dto): self
	{
		$documentId = \CBPHelper::normalizeComplexDocumentId($dto->complexDocumentId);
		if (!$documentId)
		{
			return $this;
		}

		$complexType = null;
		if ($dto->complexDocumentType && \CBPHelper::isEqualDocumentEntity($documentId, $dto->complexDocumentType))
		{
			$complexType = \CBPHelper::normalizeComplexDocumentId($dto->complexDocumentType);
		}

		$document = new Document($documentId, $complexType);
		if ($dto->changedFieldNames)
		{
			$document->setChangedFieldNames($dto->changedFieldNames);
		}

		$this->processStarter?->setDocument($document);
		$this->automationStarter?->setDocument($document);

		return $this;
	}

	public function setParameters(array | string $values): self
	{
		if (is_string($values))
		{
			$values = \CBPDocument::unSignParameters($values);
		}

		if (!$values)
		{
			return $this;
		}

		$parameters = new Parameters($values);
		$this->processStarter?->setParameters($parameters);
		$this->automationStarter?->setParameters($parameters);

		return $this;
	}

	public function setValidateParameters(bool $validateParameters = true): self
	{
		if ($this->processStarter)
		{
			$this->processStarter->config->validateParameters = $validateParameters;
		}

		if ($this->automationStarter)
		{
			$this->automationStarter->config->validateParameters = $validateParameters;
		}

		return $this;
	}

	public function setUser(int $userId): self
	{
		if ($userId <= 0)
		{
			return $this;
		}

		$this->processStarter?->setUser($userId);
		$this->automationStarter?->setUser($userId);

		return $this;
	}

	public function setMetaData(MetaDataDto $metaDataDto): self
	{
		$metaData = new MetaData(
			timeToStart: $metaDataDto->timeToStart,
		);

		$this->processStarter?->setMetaData($metaData);
		$this->automationStarter?->setMetaData($metaData);

		return $this;
	}
	public function setTemplateIds(array $templateIds): self
	{
		Collection::normalizeArrayValuesByInt($templateIds, false);

		$this->processStarter?->setTemplateIds($templateIds);
		$this->automationStarter?->setTemplateIds($templateIds);

		return $this;
	}

	public function setCheckConstants(bool $checkConstants = true): self
	{
		if ($this->processStarter)
		{
			$this->processStarter->config->checkConstants = $checkConstants;
		}

		// for automation always disable check constants

		return $this;
	}

	public function setContext(ContextDto $contextDto): self
	{
		$moduleId =
			ModuleManager::isValidModule($contextDto->moduleId) && ModuleManager::isModuleInstalled($contextDto->moduleId)
				? $contextDto->moduleId
				: ''
		;

		$context = new Context(
			$moduleId,
			$contextDto->face,
		);

		$this->processStarter?->setContext($context);
		$this->automationStarter?->setContext($context);

		return $this;
	}

	/**
	 * @param string $code
	 * @param DocumentDto[] $documents
	 * @param array $parameters
	 * @param int $eventType
	 * @param int $userId
	 *
	 * @return Starter
	 */
	public function addEvent(
		string $code,
		array $documents = [],
		array $parameters = [],
		int $eventType = \CBPDocumentEventType::Trigger,
		int $userId = 0,
	): self
	{
		if (empty($documents))
		{
			$this->addProcessEvent($code, $parameters, $eventType, $userId);

			return $this;
		}

		foreach ($documents as $document)
		{
			$documentType =
				(new InspectorService())
					->getValidComplexType($document->complexDocumentId, $document->complexDocumentType)
			;
			if (!$documentType)
			{
				continue;
			}

			$eventDocument = new Document($document->complexDocumentId, $documentType);
			$this->addAutomationEvent($code, $parameters, $eventType, $userId, $eventDocument);
			$this->addProcessEvent($code, $parameters, $eventType, $userId, $eventDocument);
		}

		return $this;
	}

	private function addAutomationEvent(
		string $code,
		array $parameters,
		int $eventType,
		int $userId,
		Document $document
	): void
	{
		$documentService = \CBPRuntime::getRuntime()->getDocumentService();

		if ($this->automationStarter)
		{
			$trigger = $documentService->getTriggerByCode($code, $document->complexType);
			if ($trigger)
			{
				$event =
					(new Event($trigger, $parameters))
						->setDocument($document)
				;
				$event->setEventType($eventType);
				$event->setUserId($userId);
				$this->automationStarter->addEvent($event);
			}
		}
	}

	private function addProcessEvent(
		string $code,
		array $parameters,
		int $eventType,
		int $userId,
		?Document $document = null,
	): void
	{
		if ($this->processStarter)
		{
			$searcher = new Searcher();
			if ($searcher->isActivityExists($code))
			{
				$event = (new Event($code, $parameters));
				if ($document)
				{
					$event->setDocument($document);
				}

				$event->setEventType($eventType);
				$event->setUserId($userId);

				$this->processStarter->addEvent($event);
			}
		}
	}

	public function start(): StartResult
	{
		$result = new StartResult();

		if (!self::isEnabled())
		{
			return $result;
		}

		$this->startProcessStarter($result);
		$this->startAutomationStarter($result);

		return $result;
	}

	private function startProcessStarter(StartResult $result): void
	{
		if ($this->processStarter)
		{
			$processResult = $this->processStarter->run();
			if (!$processResult->isSuccess())
			{
				$result->addErrors($processResult->getErrors());
			}
			$result->addWorkflowIds($processResult->getWorkflowIds());
			$result->setProcessTriggerApplied($processResult->isTriggerApplied());
		}
	}

	private function startAutomationStarter(StartResult $result): void
	{
		if ($this->automationStarter)
		{
			$automationResult = $this->automationStarter->run();
			if (!$automationResult->isSuccess())
			{
				$result->addErrors($automationResult->getErrors());
			}
			$result->addWorkflowIds($automationResult->getWorkflowIds());
			$result->setAutomationTriggerApplied($automationResult->isTriggerApplied());
		}
	}
}
