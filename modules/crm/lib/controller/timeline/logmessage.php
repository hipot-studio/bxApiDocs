<?php

namespace Bitrix\Crm\Controller\Timeline;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\Controller;
use Bitrix\Crm\Timeline\Entity\Object\Timeline;
use Bitrix\Crm\Timeline\Entity\TimelineTable;
use Bitrix\Crm\Timeline\LogMessageType;
use Bitrix\Crm\Timeline\TimelineEntry;
use Bitrix\Crm\Timeline\TimelineType;
use Bitrix\Main\Engine\Response\DataType\Page;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\Result;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;
use CRestServer;
use Bitrix\Crm\Timeline\Entity\TimelineBindingTable;

class LogMessage extends Base
{
	protected const PAGE_ID = 'logMessages';
	protected const PAGE_SIZE = 10;
	protected const SELECT_FIELDS = [
		'ASSOCIATED_ENTITY_TYPE_ID',
		'ASSOCIATED_ENTITY_ID',
		'SETTINGS',
		'CREATED',
		'AUTHOR_ID',
	];

	protected TimelineTable $timelineTable;

	protected function init(): void
	{
		parent::init();

		$this->timelineTable = new TimelineTable();
	}

	// region ACTIONS
	// 'crm.timeline.logmessage.get' method handler
	public function getAction(int $id): ?array
	{
		$item = $this->getTimelineItem($id);
		if (!$item)
		{
			$this->addError(
				new Error("Timeline logmessage not found for id `$id`", ErrorCode::NOT_FOUND)
			);

			return null;
		}
		$bindings = $this->getTimelineItemBindings($id);
		$hasPermissions = false;
		foreach ($bindings as $binding)
		{
			if (Container::getInstance()->getUserPermissions()->item()->canReadItemIdentifier($binding))
			{
				$hasPermissions = true;
				break;
			}
		}

		if (!$hasPermissions)
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		return [
			'logMessage' => $this->getPreparedLogMessage($item),
		];
	}

	// 'crm.timeline.logmessage.list' method handler
	public function listAction(int $entityTypeId, int $entityId, ?array $order = null, $offset = 0): ?Page
	{
		if (!Container::getInstance()->getUserPermissions()->item()->canRead($entityTypeId, $entityId))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$this->prepareOrder($order);

		$logMessages = $this->timelineTable::getList([
			'select' => self::SELECT_FIELDS,
			'filter' => $this->getListFilter($entityTypeId, $entityId),
			'order' => $order,
			'limit' => self::PAGE_SIZE,
			'offset' => $offset,
		])
			->fetchCollection()
			->getAll()
		;

		$result = [];
		foreach ($logMessages as $logMessage)
		{
			$result[] = $this->getPreparedLogMessage($logMessage);
		}

		return new Page(
			self::PAGE_ID,
			$result,
			fn() => $this->getTotalCount($entityTypeId, $entityId)
		);
	}

	// 'crm.timeline.logmessage.add' method handler
	public function addAction(array $fields, CRestServer $server): ?array
	{
		$preparedFields = $this->getPreparedRequiredFields($fields);
		if (!$preparedFields)
		{
			return null;
		}

		$entityTypeId = $preparedFields['entityTypeId'];
		$entityId = $preparedFields['entityId'];

		if (!Container::getInstance()->getUserPermissions()->item()->canUpdate($entityTypeId, $entityId))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$settings = [
			'TITLE' => $preparedFields['title'],
			'TEXT' => $preparedFields['text'],
			'ICON_CODE' => $preparedFields['iconCode'],
			'CLIENT_ID' => $server->getClientId(),
		];

		if ($entityId <= 0 || !CCrmOwnerType::IsDefined($entityTypeId))
		{
			$this->addError(ErrorCode::getOwnerNotFoundError());

			return null;
		}

		$result = $this->timelineTable::add([
			'TYPE_ID' => TimelineType::LOG_MESSAGE,
			'TYPE_CATEGORY_ID' => LogMessageType::REST,
			'CREATED' => new DateTime(),
			'AUTHOR_ID' => Container::getInstance()->getContext()->getUserId(),
			'SETTINGS' => $settings,
			'ASSOCIATED_ENTITY_TYPE_ID' => $entityTypeId,
			'ASSOCIATED_ENTITY_ID' => $entityId,
			'ASSOCIATED_ENTITY_CLASS_NAME' => null,
		]);

		if ($result->isSuccess())
		{
			$id = $result->getId();
			$bindings = [
				[
					'ENTITY_TYPE_ID' => $entityTypeId,
					'ENTITY_ID' => $entityId,
				]
			];

			TimelineEntry::registerBindings($id, $bindings);

			Controller::getInstance()->sendPullEventOnAdd(new ItemIdentifier($entityTypeId, $entityId), $id);

			$item = $this->getTimelineItem($id);

			return [
				'logMessage' => $this->getPreparedLogMessage($item),
			];
		}

		foreach ($result->getErrors() as $error)
		{
			$this->addError($error);
		}

		return null;
	}

	// 'crm.timeline.logmessage.delete' method handler
	public function deleteAction(int $id, CRestServer $server): ?bool
	{
		if (!$this->isAdmin())
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$item = $this->getTimelineItem($id);
		if (!$item)
		{
			$this->addError(
				new Error("Log message not found for id `$id`", ErrorCode::NOT_FOUND)
			);

			return null;
		}

		if (!$this->checkClientId($item, $server))
		{
			$this->addError(
				new Error(
					'This log message can only be deleted by the application through which it was created',
					ErrorCode::REMOVING_DISABLED
				)
			);

			return null;
		}

		$result = $this->delete($item);
		if ($result->isSuccess())
		{
			return true;
		}

		foreach ($result->getErrors() as $error)
		{
			$this->addError($error);
		}

		return null;
	}
	// endregion

	protected function prepareOrder(?array &$order): void
	{
		$sortFieldNames = [
			'id',
			'created',
		];
		$sortFieldDirections = [
			'asc',
			'desc',
		];

		if (!$order)
		{
			$order = [];
		}

		$preparedOrder = [];
		foreach ($order as $fieldName => $value)
		{
			if (
				in_array($fieldName, $sortFieldNames, true)
				&& in_array($value, $sortFieldDirections, true)
			)
			{
				$preparedOrder[strtoupper($fieldName)] = strtoupper($value);
			}
		}

		if (empty($preparedOrder))
		{
			$order = [
				'ID' => 'DESC',
			];
		}
		else
		{
			$order = $preparedOrder;
		}
	}

	protected function getTotalCount(int $entityTypeId, int $entityId): ?int
	{
		$result = $this->timelineTable::getList([
			'select' => ['CNT'],
			'filter' => $this->getListFilter($entityTypeId, $entityId),
			'runtime' => [
				new ExpressionField('CNT', 'COUNT(*)'),
			],
		])->fetch();

		if ($result)
		{
			return (int)$result['CNT'];
		}

		return null;
	}

	protected function getListFilter(int $entityTypeId, int $entityId): array
	{
		return [
			'=TYPE_ID' => TimelineType::LOG_MESSAGE,
			'=TYPE_CATEGORY_ID' => LogMessageType::REST,
			'=ASSOCIATED_ENTITY_TYPE_ID' => $entityTypeId,
			'=ASSOCIATED_ENTITY_ID' => $entityId,
		];
	}

	protected function getPreparedRequiredFields(array $fields): ?array
	{
		$requiredFields = [
			'entityTypeId' => fn($value): int => (int) $value,
			'entityId' => fn($value): int => (int) $value,
			'title' => fn($value): string => (string) $value,
			'text' => fn($value): string => (string) $value,
			'iconCode' => fn($value): string => (string) $value,
		];

		foreach ($requiredFields as $fieldName => $cast)
		{
			if (!isset($fields[$fieldName]))
			{
				$this->addError(new Error('Missing a required field: ' . $fieldName));

				return null;
			}

			$fields[$fieldName] = $cast($fields[$fieldName]);
		}

		return $fields;
	}

	protected function isAdmin(): bool
	{
		return Container::getInstance()->getUserPermissions()->isAdmin();
	}

	protected function getTimelineItem(int $id): ?Timeline
	{
		return TimelineTable::query()
			->setSelect(self::SELECT_FIELDS)
			->where('ID', $id)
			->where('TYPE_ID', TimelineType::LOG_MESSAGE)
			->where('TYPE_CATEGORY_ID', LogMessageType::REST)
			->fetchObject()
		;
	}

	protected function checkClientId(Timeline $item, CRestServer $server): bool
	{
		$settings = $item->getSettings();
		$itemClientId = ($settings['CLIENT_ID'] ?? null);

		$clientId = $server->getClientId();

		return ($clientId && $itemClientId === $clientId);
	}

	protected function delete(Timeline $timeline): Result
	{
		return $timeline->delete();
	}

	private function getPreparedLogMessage(?Timeline $item): array
	{
		if (!$item)
		{
			return [];
		}

		$settings = $item->getSettings();

		return [
			'id' => $item->getId(),
			'created' => $item->getCreated(),
			'authorId' => $item->getAuthorId(),
			'title' => $settings['TITLE'] ?? '',
			'text' => $settings['TEXT'] ?? '',
			'iconCode' => $settings['ICON_CODE'] ?? '',
		];
	}

	/**
	 * @return ItemIdentifier[]
	 */
	protected function getTimelineItemBindings(int $id): array
	{
		$result = [];

		$bindings = TimelineBindingTable::query()
			->where('OWNER_ID', $id)
			->setSelect(['ENTITY_TYPE_ID', 'ENTITY_ID'])
			->exec()
		;

		while ($binding = $bindings->fetch())
		{
			$itemId = ItemIdentifier::createFromArray($binding);
			if ($itemId)
			{
				$result[] = $itemId;
			}
		}

		return $result;
	}
}
