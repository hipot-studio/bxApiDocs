<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Crm\Automation\Factory;
use Bitrix\Crm\Integration\BizProc\Starter\Mixins\Dto\TriggerBindingDocumentsDto;
use Bitrix\Crm\Integration\BizProc\Starter\Mixins\TriggerBindingDocumentsTrait;
use Bitrix\Crm\Integration\BizProc\TestRunEntity;
use Bitrix\Crm\Service\Container;
use Bitrix\Main;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

if (!Main\Loader::includeModule('bizproc'))
{
	return;
}

/**
 * Base of the bridged CRM triggers: the classes the crmautomationtrigger bridge exposes as designer
 * nodes and classical automation runs as triggers.
 *
 * Every node owns the fields it exposes, so the designer never offers a field the workflow would
 * always receive empty, and the date/time carries a name that says which moment of that node it is.
 */
class BaseTrigger extends \Bitrix\Bizproc\Automation\Trigger\BaseTrigger
{
	use TriggerBindingDocumentsTrait;

	protected $inputData;

	/**
	 * @param int $entityTypeId Target entity id
	 * @return bool
	 */
	public static function isSupported($entityTypeId)
	{
		$supported = [
			\CCrmOwnerType::Lead,
			\CCrmOwnerType::Deal,
			\CCrmOwnerType::Order,
			\CCrmOwnerType::Invoice,
			\CCrmOwnerType::Quote,
			\CCrmOwnerType::SmartInvoice,
			\CCrmOwnerType::SmartDocument,
		];

		if (\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);

			return
				static::areDynamicTypesSupported()
				&& !is_null($factory)
				&& $factory->isAutomationEnabled()
				&& $factory->isStagesEnabled();
		}

		return in_array($entityTypeId, $supported, true);
	}

	protected static function areDynamicTypesSupported(): bool
	{
		return true;
	}

	public static function execute(array $bindings, ?array $inputData = null, bool $useEntitySearch = true)
	{
		static::resetFacilitySelector();

		$triggersSent = false;
		$triggersApplied = false;

		$documents = [];
		foreach ($bindings as $binding)
		{
			$bindingDocuments = static::getBindingDocuments(
				new TriggerBindingDocumentsDto(
					(int)$binding['OWNER_TYPE_ID'],
					(int)$binding['OWNER_ID'],
					static::getCode(),
					static::areDynamicTypesSupported(),
					$useEntitySearch,
				),
			);

			foreach ($bindingDocuments as $document)
			{
				if (!in_array($document, $documents, true))
				{
					$documents[] = $document;
					$triggersSent = true;
					if (static::sendTrigger($document, $inputData))
					{
						$triggersApplied = true;
					}
				}
			}
		}

		return (new Main\Result())->setData(['triggersSent' => $triggersSent, 'triggersApplied' => $triggersApplied]);
	}

	public function setInputData($data)
	{
		$this->inputData = $data;

		return $this;
	}

	public function getInputData($key = null)
	{
		if ($key !== null)
		{
			return is_array($this->inputData) && isset($this->inputData[$key]) ? $this->inputData[$key] : null;
		}

		return $this->inputData;
	}

	/**
	 * Bridged initiator resolution: the standard INPUT_DATA key. Event::userId is always 0 for
	 * bridged triggers, so the actor can only come from INPUT_DATA (dispatcher enrichment). A node
	 * whose dispatcher names the actor under a key of its own overrides this.
	 */
	protected function resolveEventInitiatorUserId(): ?int
	{
		$userId = (int)($this->getInputData('initiatorUserId') ?? 0);

		return $userId > 0 ? $userId : null;
	}

	/**
	 * RETURN descriptor of the event initiator, for a node whose event names an actor.
	 */
	protected static function getEventInitiatorProperty(): array
	{
		Main\Localization\Loc::loadMessages(__FILE__);

		return [
			'Id' => static::EVENT_INITIATOR_ID,
			'Name' => Main\Localization\Loc::getMessage('CRM_AUTOMATION_TRIGGER_EVENT_PACKAGE_INITIATOR') ?? '',
			'Type' => FieldType::USER,
			'Default' => null,
		];
	}

	/**
	 * RETURN descriptor of the event date/time. The name belongs to the node: it has to say which
	 * moment of that particular event the field carries.
	 */
	protected static function getEventDateTimeProperty(string $name): array
	{
		return [
			'Id' => static::EVENT_DATE_TIME_ID,
			'Name' => $name,
			'Type' => FieldType::DATETIME,
			'Default' => null,
		];
	}

	/**
	 * RETURN value of the event initiator. An actor the input data does not name becomes a typed empty
	 * value (null for a user field).
	 */
	protected function buildEventInitiatorValue(): ?string
	{
		$userId = $this->resolveEventInitiatorUserId();

		return $userId > 0 ? 'user_' . $userId : null;
	}

	/**
	 * Event date/time: the moment the trigger is executed, as a string in the culture format.
	 *
	 * The RETURN values are merged into the workflow start parameters, stored as gzcompress(Json::encode(...)):
	 * a DateTime object comes back from that channel as an empty array, so only a scalar survives. The format
	 * must stay in the culture format.
	 */
	protected static function buildEventDateTimeValue(): string
	{
		return (new Main\Type\DateTime())->format(Main\Type\DateTime::getFormat());
	}

	protected static function sendTrigger(array $document, ?array $inputData = null)
	{
		[$entityTypeId, $entityId] = $document;
		if (TestRunEntity::isTestRun((int)$entityTypeId, (int)$entityId))
		{
			return false;
		}

		if (!Factory::isAutomationRunnable($entityTypeId))
		{
			return false;
		}

		$automationTarget = Factory::getTarget($entityTypeId, $entityId);
		$trigger = new static();
		$trigger->setTarget($automationTarget);
		if ($inputData !== null)
		{
			$trigger->setInputData($inputData);
		}

		if (Starter::isEnabled())
		{
			Starter::getByScenario(Scenario::onEvent)
				->addEvent(
					$trigger::class,
					[],
					[
						'INPUT_DATA' => $inputData,
						'TARGET' => $automationTarget,
						'TRIGGER_CLASS' => $trigger::class,
					],
				)
				->start()
			;
		}

		return $trigger->send();
	}

	public function send()
	{
		if (method_exists(\Bitrix\Bizproc\Automation\Trigger\BaseTrigger::class, 'send'))
		{
			return parent::send();
		}

		$applied = false;
		$triggers = $this->getPotentialTriggers();
		if ($triggers)
		{
			foreach ($triggers as $trigger)
			{
				if ($this->checkApplyRules($trigger))
				{
					$this->applyTrigger($trigger);
					$applied = true;

					break;
				}
			}
		}

		return $applied;
	}

	protected function applyTrigger(array $trigger)
	{
		$statusId = $trigger['DOCUMENT_STATUS'];

		/** @var \Bitrix\Crm\Automation\Target\BaseTarget $target */
		$target = $this->getTarget();

		if (is_callable([$this, 'getReturnValues']))
		{
			$trigger['RETURN'] = $this->getReturnValues();
		}

		$executeBy = null;

		if (isset($trigger['APPLY_RULES']['ExecuteBy']))
		{
			$docId = $target->getDocumentType();
			$docId[2] = $target->getDocumentId();
			$executeBy = \CBPHelper::ExtractUsers($trigger['APPLY_RULES']['ExecuteBy'], $docId, true);
		}

		$target->setAppliedTrigger($trigger);
		$result = $target->setEntityStatus($statusId, $executeBy);

		//Fake document update for clearing document cache
		$ds = \CBPRuntime::GetRuntime(true)->getDocumentService();
		$ds->UpdateDocument($target->getComplexDocumentId(), []);

		if ($result !== false)
		{
			Factory::onFieldsChanged(
				$target->getEntityTypeId(),
				$target->getEntityId(),
				[$target->getEntityTypeId() === \CCrmOwnerType::Lead ? 'STATUS_ID' : 'STAGE_ID'],
			);
			// $executeBy is the user the trigger applies the status on behalf of
			// (CBPHelper::ExtractUsers with $bFirst=true returns a single int user id or
			// null). Forward it as the automation initiator so a stage robot attributes
			// history to them; null keeps the previous System behavior.
			Factory::runOnStatusChanged(
				$target->getEntityTypeId(),
				$target->getEntityId(),
				is_int($executeBy) ? $executeBy : null
			);
		}

		return true;
	}

	public static function getNodeName(): string
	{
		return static::getName();
	}

	public static function getNodeDescription(): string
	{
		return static::getDescription();
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::CYAN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::ACTIVITY->name;
	}

	public static function getNodeGroups(): array
	{
		return [];
	}
}
