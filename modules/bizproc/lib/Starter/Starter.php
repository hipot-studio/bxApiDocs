<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Public\Service\Document\InspectorService;
use Bitrix\Bizproc\Starter\Constraint\BPDesignerConstraint;
use Bitrix\Bizproc\Starter\Constraint\ScenarioBPDesignerConstraint;
use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Dto\DocumentDto;
use Bitrix\Bizproc\Starter\Dto\MetaDataDto;
use Bitrix\Bizproc\Starter\Dto\ParentWorkflowDto;
use Bitrix\Bizproc\Starter\Dto\StarterDto;
use Bitrix\Bizproc\Starter\Dto\StarterConfigDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Result\StartResult;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\ModuleManager;

final class Starter
{
	private ?ProcessStarterStacker $processStarterStacker;
	private ?AutomationStarter $automationStarter;

	public static function isEnabled(): bool
	{
		return true;
	}

	public static function getByScenario(Scenario $scenario): Starter
	{
		$processDto = new StarterConfigDto(scenario: $scenario);
		$automationDto = new StarterConfigDto(scenario: $scenario, validateParameters: false, checkConstants: false);

		if ($scenario === Scenario::onEvent)
		{
			$processDto->constraints[] = ServiceLocator::getInstance()->get(BPDesignerConstraint::class);

			$processDto->validateParameters = false;
			$processDto->checkConstants = false;
		}

		return match ($scenario)
		{
			Scenario::onRest => new self(
				new StarterDto(
					process: new StarterConfigDto(
						scenario: $scenario,
						checkFeature: false,
						checkLimits: false,
						checkConstants: false,
					),
					automation: new StarterConfigDto(
						scenario: $scenario,
						checkFeature: false,
						checkLimits: false,
						validateParameters: false,
						checkConstants: false,
					),
				),
			),
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
			Scenario::onEvent,
				=> new self(
					new StarterDto(process: $processDto, automation: $automationDto)
				),
			Scenario::onScript => new self(
				new StarterDto(automation: $automationDto)
			),
			default => new self(new StarterDto())
		};
	}

	public function __construct(StarterDto $dto)
	{
		$this->processStarterStacker = $dto->process
			? new ProcessStarterStacker([
				new ProcessStarter($dto->process),
				new ScenarioProcessStarter($this->tweakConfigForScenarioProcessStarter($dto->process)),
			])
			: null;
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

		$this->processStarterStacker?->setDocument($document);
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
		$this->processStarterStacker?->setParameters($parameters);
		$this->automationStarter?->setParameters($parameters);

		return $this;
	}

	public function setValidateParameters(bool $validateParameters = true): self
	{
		$this->processStarterStacker?->setValidateParameters($validateParameters);

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

		$this->processStarterStacker?->setUser($userId);
		$this->automationStarter?->setUser($userId);

		return $this;
	}

	public function setMetaData(MetaDataDto $metaDataDto): self
	{
		$metaData = new MetaData(
			timeToStart: $metaDataDto->timeToStart,
		);

		$this->processStarterStacker?->setMetaData($metaData);
		$this->automationStarter?->setMetaData($metaData);

		return $this;
	}

	public function setParentWorkflow(ParentWorkflowDto $parentWorkflow): self
	{
		$this->processStarterStacker?->setParentWorkflow($parentWorkflow);
		$this->automationStarter?->setParentWorkflow($parentWorkflow);

		return $this;
	}

	public function setTemplateIds(array $templateIds): self
	{
		Collection::normalizeArrayValuesByInt($templateIds, false);

		$this->processStarterStacker?->setTemplateIds($templateIds);
		$this->automationStarter?->setTemplateIds($templateIds);

		return $this;
	}

	public function setCheckConstants(bool $checkConstants = true): self
	{
		$this->processStarterStacker?->setCheckConstants($checkConstants);

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

		if ($contextDto->isManual)
		{
			$context->setIsManual();
		}

		$this->processStarterStacker?->setContext($context);
		$this->automationStarter?->setContext($context);

		return $this;
	}

	public function setDelay(?int $delay = null): self
	{
		$this->processStarterStacker?->setDelay($delay);
		$this->automationStarter?->setDelay($delay);

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
		if ($this->processStarterStacker)
		{
			$event = (new Event($code, $parameters));
			if ($document)
			{
				$event->setDocument($document);
			}

			$event->setEventType($eventType);
			$event->setUserId($userId);

			$this->processStarterStacker->addEvent($event);
		}
	}

	public function start(): StartResult
	{
		$result = new StartResult();

		if (!self::isEnabled())
		{
			return $result;
		}

		$this->startProcessStarterStacker($result);
		$this->startAutomationStarter($result);

		return $result;
	}

	private function startProcessStarterStacker(StartResult $result): void
	{
		if ($this->processStarterStacker)
		{
			$stackerResult = $this->processStarterStacker->run();
			if (!$stackerResult->isSuccess())
			{
				$result->addErrors($stackerResult->getErrors());
			}
			$result->addWorkflowIds($stackerResult->getWorkflowIds());
			$result->addTemplateWorkflowIds($stackerResult->getTemplateWorkflowIds());
			$result->setProcessTriggerApplied($stackerResult->isTriggerApplied());
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
			$result->addTemplateWorkflowIds($automationResult->getTemplateWorkflowIds());
			$result->setAutomationTriggerApplied($automationResult->isTriggerApplied());
		}
	}
	
	private function tweakConfigForScenarioProcessStarter(StarterConfigDto $starterConfigDto): StarterConfigDto
	{
		$config = clone $starterConfigDto;

		$config->constraints = [];
		foreach ($starterConfigDto->constraints as $constraint)
		{
			if ($constraint instanceof BPDesignerConstraint)
			{
				$config->constraints[] = ServiceLocator::getInstance()->get(ScenarioBPDesignerConstraint::class);
				continue;
			}

			$config->constraints[] = $constraint;
		}

		return $config;
	}
}
