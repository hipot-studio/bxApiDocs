<?php

namespace Bitrix\Crm\Integration\BizProc\Starter;

use Bitrix\Bizproc\Starter\Dto\StarterConfigDto;
use Bitrix\Bizproc\Starter\Dto\StarterDto;
use Bitrix\Bizproc\Starter\Enum\Face;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Crm\Automation\Factory;
use Bitrix\Crm\Automation\Trigger\BaseTrigger;
use Bitrix\Crm\Automation\Trigger\FieldChangedTrigger;
use Bitrix\Crm\Automation\Trigger\ResponsibleChangedTrigger;
use Bitrix\Crm\Integration\BizProc\Starter\Dto\DocumentDto;
use Bitrix\Crm\Integration\BizProc\Starter\Dto\EventDto;
use Bitrix\Crm\Integration\BizProc\Starter\Dto\RunDataDto;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Crm\Service\Container;
use CCrmBizProcHelper;

final class CrmStarter
{
	public const AUTOMATION_SCOPE = 'automation';
	public const REST_SCOPE = 'rest';
	public const MOVE_TO_BACKGROUND_DELAY = 0;
	private const CREATE_DOCUMENT_TRIGGER = 'CrmEntityCreateTrigger';
	private array $complexId;
	private DocumentDto $document;
	private string $contextModuleId = 'crm';

	public function __construct(DocumentDto $document)
	{
		$complexId = \CCrmBizProcHelper::ResolveDocumentId($document->entityTypeId, $document->entityId);
		if (!$complexId)
		{
			throw new ArgumentException('Invalid value for entityTypeId', 'entityTypeId');
		}

		$this->complexId = $complexId;
		$this->document = $document;
	}

	public function setContextModuleId(string $moduleId): static
	{
		$this->contextModuleId = $moduleId;

		return $this;
	}

	public function runOnDocumentAdd(RunDataDto $dto): Result
	{
		$result = new Result();

		$freeScenarioResult = $this->runLeadFreeScenarioIfEnabled();
		if ($freeScenarioResult)
		{
			return $result->setConversionResult($freeScenarioResult);
		}

		$starter = $this->getStarter(true, $dto);
		if ($starter)
		{
			$starter->setValidateParameters(false);
			$result->addErrors($starter->start()->getErrors());
		}
		else
		{
			$processResult = $this->runProcess($dto, \CCrmBizProcEventType::Create);
			$result->addErrors($processResult->getErrors());
			if ($processResult->getConversionResult())
			{
				$result->setConversionResult($processResult->getConversionResult());
			}

			// region automation
			foreach ($dto->events as $event)
			{
				$this->executeTrigger($event);
			}

			$automationResult = $this->runAutomation($dto, \CCrmBizProcEventType::Create);
			$result->addErrors($automationResult->getErrors());
			if ($automationResult->getConversionResult())
			{
				$result->setConversionResult($automationResult->getConversionResult());
			}
		}

		return $this->addConversionResult($result);
	}

	public function runOnInnerDocumentAdd(
		RunDataDto $dto,
		bool $runAutomation = true,
		bool $runProcess = true,
	): Result
	{
		$freeScenarioResult = $this->runLeadFreeScenarioIfEnabled();
		if ($freeScenarioResult)
		{
			return (new Result())->setConversionResult($freeScenarioResult);
		}

		return $this->runOnInnerDocument(
			$dto,
			\CCrmBizProcEventType::Create,
			runAutomation: $runAutomation,
			runProcess: $runProcess,
		);
	}

	public function runOnInnerDocumentUpdate(
		RunDataDto $dto,
		bool $runAutomation = true,
		bool $runProcess = true,
	): Result
	{
		return $this->runOnInnerDocument(
			$dto,
			\CCrmBizProcEventType::Edit,
			runAutomation: $runAutomation,
			runProcess: $runProcess,
		);
	}

	private function runOnInnerDocument(
		RunDataDto $dto,
		int $eventType,
		bool $runAutomation,
		bool $runProcess,
	): Result
	{
		$result = new Result();
		$canStartBizProcWithinBizProc = Option::get('crm', 'start_bp_within_bp', 'N') === 'Y';
		$isAdd = $eventType === \CCrmBizProcEventType::Create;

		if ($runProcess && $runAutomation && $canStartBizProcWithinBizProc)
		{
			$starter = $this->getStarter($isAdd, $dto);
			if ($starter)
			{
				$starter->setValidateParameters(false);
				$startResult = $starter->start();
				$result->addErrors($startResult->getErrors());

				return $this->addConversionResult($result);
			}
		}

		if ($runProcess && $canStartBizProcWithinBizProc)
		{
			$processResult = $this->runProcess($dto, $eventType);
			$result->addErrors($processResult->getErrors());
			if ($processResult->getConversionResult())
			{
				$result->setConversionResult($processResult->getConversionResult());
			}
		}

		if ($runAutomation)
		{
			$automationResult = $this->runAutomation($dto, $eventType);
			$result->addErrors($automationResult->getErrors());
			if ($automationResult->getConversionResult())
			{
				$result->setConversionResult($automationResult->getConversionResult());
			}
		}

		return $this->addConversionResult($result);
	}

	public function runOnDocumentUpdate(RunDataDto $dto): Result
	{
		$result = new Result();

		$starter = $this->getStarter(false, $dto);
		if ($starter)
		{
			$starter->setValidateParameters(false);
			$result->addErrors($starter->start()->getErrors());
		}
		else
		{
			$processResult = $this->runProcess($dto, \CCrmBizProcEventType::Edit);
			$result->addErrors($processResult->getErrors());
			if ($processResult->getConversionResult())
			{
				$result->setConversionResult($processResult->getConversionResult());
			}

			foreach ($dto->events as $event)
			{
				$this->executeTrigger($event);
			}

			$automationResult = $this->runAutomation($dto, \CCrmBizProcEventType::Edit);
			$result->addErrors($automationResult->getErrors());
			if ($automationResult->getConversionResult())
			{
				$result->setConversionResult($automationResult->getConversionResult());
			}
		}

		return $this->addConversionResult($result);
	}

	// todo: runOnEvents

	public function runProcess(RunDataDto $dto, int $eventType): Result
	{
		$result = new Result();

		if ($this->isStarterEnabled())
		{
			$result->addErrors($this->createProcessStarter($dto, $eventType)->start()->getErrors());
		}
		else
		{
			$errors = [];
			\CCrmBizProcHelper::AutoStartWorkflows(
				$this->document->entityTypeId,
				$this->document->entityId,
				$eventType,
				$errors,
				$dto->parameters,
			);
			foreach ($errors as $error)
			{
				$customData = array_diff_key($errors, ['message' => '', 'code' => '']);

				$result->addError(
					new Error($error['message'], $error['code'], $customData)
				);
			}
		}

		return $this->addConversionResult($result);
	}

	public function runAutomation(RunDataDto $dto, int $eventType): Result
	{
		$result = new Result();

		if ($eventType === \CCrmBizProcEventType::Create)
		{
			$freeScenarioResult = $this->runLeadFreeScenarioIfEnabled();
			if ($freeScenarioResult)
			{
				return $result->setConversionResult($freeScenarioResult);
			}
		}

		if ($this->isStarterEnabled())
		{
			$starter =
				(new Starter(
					new StarterDto(
						automation: new StarterConfigDto(
							scenario: $this->getScenarioByScope($dto->scope, $eventType === \CCrmBizProcEventType::Create),
							validateParameters: false,
							checkConstants: false,
						),
					)
				))
			;
			$this->fillStarterByRunDto($dto, $starter);
			if ($eventType !== \CCrmBizProcEventType::Create)
			{
				$this->fillStarterWithCommonTriggers($dto, $starter);
			}

			$result->addErrors($starter->start()->getErrors());
		}
		elseif (Factory::isAutomationAvailable($this->document->entityTypeId))
		{
			$starter = new \Bitrix\Crm\Automation\Starter($this->document->entityTypeId, $this->document->entityId);
			if ($dto->userId > 0)
			{
				$starter->setUserId($dto->userId);
			}

			$starter->setContextModuleId($this->contextModuleId);

			if ($dto->scope === self::AUTOMATION_SCOPE)
			{
				$starter->setContextToBizproc();
			}
			elseif ($dto->scope === self::REST_SCOPE)
			{
				$starter->setContextToRest();
			}

			if ($eventType === \CCrmBizProcEventType::Create)
			{
				$runResult = $starter->runOnAdd();
			}
			else
			{
				$runResult = $starter->runOnUpdate($dto->actualFields ?? [], $dto->previousFields ?? []);
			}

			$result->addErrors($runResult->getErrors());
			if ($runResult->getConversionResult())
			{
				$result->setConversionResult($runResult->getConversionResult());
			}
		}

		return $this->addConversionResult($result);
	}

	private function runLeadFreeScenarioIfEnabled(): ?\Bitrix\Crm\Automation\Converter\Result
	{
		if ($this->document->entityTypeId === \CCrmOwnerType::Lead && !\Bitrix\Crm\Settings\LeadSettings::isEnabled())
		{
			return
				(new \Bitrix\Crm\Automation\Starter($this->document->entityTypeId, $this->document->entityId))
					->runOnAdd()
					->getConversionResult()
			;
		}

		return null;
	}

	private function addConversionResult(Result $result): Result
	{
		$conversionResult = Factory::shiftConversionResult($this->document->entityTypeId, $this->document->entityId);
		if ($conversionResult)
		{
			$result->setConversionResult($conversionResult);
		}

		return $result;
	}

	private function getScenarioByScope(string $scope, bool $isNew = false): Scenario
	{
		if (in_array($scope, [self::REST_SCOPE, self::AUTOMATION_SCOPE], true))
		{
			return $isNew ? Scenario::onDocumentInnerAdd : Scenario::onDocumentInnerUpdate;
		}

		return $isNew ? Scenario::onDocumentAdd : Scenario::onDocumentUpdate;
	}

	private function getStarter(bool $isNew, RunDataDto $dto): ?Starter
	{
		if ($this->isStarterEnabled())
		{
			$starter = Starter::getByScenario($isNew ? Scenario::onDocumentAdd : Scenario::onDocumentUpdate);
			$this->fillStarterByRunDto($dto, $starter);
			if ($isNew)
			{
				$this->addCreateDocumentTriggerEvent($dto, $starter);
			}

			foreach ($dto->events as $event)
			{
				$starter->addEvent(
					$event->triggerCode,
					$this->convertEventDocumentsToDocumentDto($event->documents),
					$event->parameters
				);
			}

			if (!$isNew)
			{
				$this->fillStarterWithCommonTriggers($dto, $starter);
			}

			return $starter;
		}

		return null;
	}

	private function createProcessStarter(RunDataDto $dto, int $eventType): Starter
	{
		$starter =
			(new Starter(
				new StarterDto(
					process: new StarterConfigDto(
						scenario: $this->getScenarioByScope($dto->scope, $eventType === \CCrmBizProcEventType::Create),
						validateParameters: false,
					),
				)
			))
		;

		$this->fillStarterByRunDto($dto, $starter);

		if ($eventType === \CCrmBizProcEventType::Create)
		{
			$this->addCreateDocumentTriggerEvent($dto, $starter);
		}
		else
		{
			$this->fillStarterWithCommonTriggers($dto, $starter);
		}

		return $starter;
	}

	private function addCreateDocumentTriggerEvent(RunDataDto $dto, Starter $starter): void
	{
		$complexDocumentId = $this->resolveComplexDocumentIdFromDocument($this->document);
		if (!$complexDocumentId)
		{
			return;
		}

		$parameters = ['Document' => $complexDocumentId];

		$categoryId = $this->resolveCategoryId($dto);
		if ($categoryId !== null)
		{
			$parameters['CategoryId'] = $categoryId;
		}

		$starter->addEvent(
			self::CREATE_DOCUMENT_TRIGGER,
			$this->convertEventDocumentsToDocumentDto([$this->document]),
			$parameters,
			\CBPDocumentEventType::Create,
		);
	}

	private function resolveCategoryId(RunDataDto $dto): ?int
	{
		if ($dto->categoryId !== null)
		{
			return $dto->categoryId;
		}

		$factory = Container::getInstance()->getFactory($this->document->entityTypeId);
		if (!$factory || !$factory->isCategoriesSupported())
		{
			return null;
		}

		$actualCategoryId = $dto->actualFields[\Bitrix\Crm\Item::FIELD_NAME_CATEGORY_ID] ?? null;
		if ($actualCategoryId !== null)
		{
			return (int)$actualCategoryId;
		}

		$item = $factory->getItem($this->document->entityId, [\Bitrix\Crm\Item::FIELD_NAME_CATEGORY_ID]);

		return $item?->getCategoryId();
	}

	private function fillStarterWithCommonTriggers(RunDataDto $dto, Starter $starter): void
	{
		$changedFields = $this->computeChangedFields($dto->actualFields ?? [], $dto->previousFields ?? []);
		if (!$changedFields)
		{
			return;
		}

		$events = [
			new EventDto(FieldChangedTrigger::getCode(), [$this->document], ['CHANGED_FIELDS' => $changedFields]), // automation
			new EventDto(
				'CrmEntityFieldChangedTrigger',
				[$this->document],
				[
					'Fields' => $changedFields,
					'Document' => $this->resolveComplexDocumentIdFromDocument($this->document),
				],
			), // process
		];

		$responsibleKey = (
			$this->document->entityTypeId === \CCrmOwnerType::Order
				? 'RESPONSIBLE_ID'
				: 'ASSIGNED_BY_ID'
		);
		if (in_array($responsibleKey, $changedFields, true))
		{
			$events[] = new EventDto(ResponsibleChangedTrigger::getCode(), [$this->document]);

			$target = Factory::getTarget($this->document->entityTypeId, $this->document->entityId);
			$events[] = new EventDto(
				ResponsibleChangedTrigger::class,
				[],
				[
					'TARGET' => $target,
					'INPUT_DATA' => $changedFields,
					'TRIGGER_CLASS' => ResponsibleChangedTrigger::class,
				]
			);
		}

		foreach ($events as $event)
		{
			$starter->addEvent(
				$event->triggerCode,
				$this->convertEventDocumentsToDocumentDto($event->documents),
				$event->parameters
			);
		}
	}

	private function fillStarterByRunDto(RunDataDto $dto, Starter $starter): void
	{
		$face = $dto->scope === self::REST_SCOPE ? Face::REST : Face::WEB;

		$starter
			->setDocument(new \Bitrix\Bizproc\Starter\Dto\DocumentDto(
				complexDocumentId: $this->complexId,
				complexDocumentType: CCrmBizProcHelper::ResolveDocumentType($this->document->entityTypeId),
				changedFieldNames: $this->computeChangedFields(
					$dto->actualFields ?? [],
					$dto->previousFields ?? []
				)
			))
			->setContext(
				$this->createContextDto($face, $dto->isManual)
			)
			->setParameters($dto->parameters ?? [])
			->setUser($dto->userId)
		;

		if ($dto->delay !== null && method_exists($starter, 'setDelay'))
		{
			$starter->setDelay($dto->delay);
		}
	}

	private function createContextDto($face, $isManual)
	{
		$reflection = new \ReflectionClass(\Bitrix\Bizproc\Starter\Dto\ContextDto::class);
		$constructor = $reflection->getConstructor();
		$hasIsManual = false;

		if ($constructor)
		{
			foreach ($constructor->getParameters() as $param)
			{
				if ($param->getName() === 'isManual')
				{
					$hasIsManual = true;
					break;
				}
			}
		}

		if ($hasIsManual)
		{
			return new \Bitrix\Bizproc\Starter\Dto\ContextDto(
				moduleId: $this->contextModuleId,
				face: $face,
				isManual: $isManual,
			);
		}

		return new \Bitrix\Bizproc\Starter\Dto\ContextDto(
			moduleId: $this->contextModuleId,
			face: $face,
		);
	}
	private function computeChangedFields(array $actualFields, array $previousFields): array
	{
		return (new DocumentFieldComparator(
			$this->document->entityTypeId, $actualFields, $previousFields
		))->compare();
	}

	private function convertEventDocumentsToDocumentDto(array $eventDocuments): array
	{
		$documents = [];
		/** @var DocumentDto[] $eventDocuments */
		foreach ($eventDocuments as $document)
		{
			$complexId = $this->resolveComplexDocumentIdFromDocument($document);
			if ($complexId)
			{
				$documents[] = new \Bitrix\Bizproc\Starter\Dto\DocumentDto(
					$complexId,
					CCrmBizProcHelper::ResolveDocumentType($document->entityTypeId)
				);
			}
		}

		return $documents;
	}

	private function resolveComplexDocumentIdFromDocument(DocumentDto $document): ?array
	{
		return CCrmBizProcHelper::resolveDocumentId($document->entityTypeId, $document->entityId);
	}

	private function executeTrigger(EventDto $event): void
	{
		$bindings = [];

		/** @var BaseTrigger $trigger */
		$trigger = null;
		foreach ($event->documents as $document)
		{
			$supportedTrigger = \CCrmDocument::getTriggerByCode(
				$event->triggerCode,
				CCrmBizProcHelper::ResolveDocumentType($document->entityTypeId),
			);
			if ($supportedTrigger)
			{
				$bindings[] = [
					'OWNER_TYPE_ID' => $document->entityTypeId,
					'OWNER_ID' => $document->entityId,
				];
				$trigger = $supportedTrigger;
			}
		}

		if ($trigger)
		{
			$trigger::execute($bindings, $event->parameters);
		}
	}

	private function isStarterEnabled(): bool
	{
		return Loader::includeModule('bizproc') && class_exists(Starter::class) && Starter::isEnabled();
	}
}
