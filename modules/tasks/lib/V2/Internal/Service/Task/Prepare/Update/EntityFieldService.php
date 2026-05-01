<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Prepare\Update;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\Control\Handler\TariffFieldHandler;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\CheckUserFields;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\ParseTags;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\PrepareFields;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\RunBeforeUpdateEvent;
use CTimeZone;
use Throwable;

class EntityFieldService
{
	/**
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws TaskUpdateException
	 */
	public function prepare(Entity\Task $task, UpdateConfig $config, array $currentTask): array
	{
		$mapper = Container::getInstance()->getOrmTaskMapper();

		$fields = $mapper->mapFromEntity($task);
		$fields = (new PrepareFields($config))($fields, $currentTask);

		$fields = (new ParseTags($config))($fields, $currentTask);
		$fields = (new RunBeforeUpdateEvent($config))(
			$fields,
			$currentTask,
			static fn (mixed $event): bool => is_array($event) && ($event['TO_CLASS'] ?? null) !== 'CTaskTimerManager',
		);

		(new CheckUserFields($config))($fields, $currentTask);

		$tariff = new TariffFieldHandler($fields);

		$fields = $tariff->getFields();

		$dbFields = $this->getFieldsToDb($fields, $config->getSkipTimeZoneFields());

		$dbFields['ID'] = $task->id;

		return [$mapper->mapToEntity($dbFields), $fields];
	}

	private function getFieldsToDb(array $fields, array $skipTimeZoneFields = []): array
	{
		$tableFields = TaskTable::getEntity()->getFields();

		foreach ($fields as $fieldName => $value)
		{
			if (!array_key_exists($fieldName, $tableFields))
			{
				unset($fields[$fieldName]);
				continue;
			}

			if (str_starts_with($fieldName, 'UF_'))
			{
				unset($fields[$fieldName]);
				continue;
			}

			if (
				$tableFields[$fieldName] instanceof DatetimeField
				&& !empty($value)
			)
			{
				in_array($fieldName, $skipTimeZoneFields, true) && CTimeZone::Disable();

				try
				{
					$fields[$fieldName] = DateTime::createFromUserTime($value);
				}
				catch (Throwable)
				{
					throw new ObjectException('Incorrect date/time');
				}
				finally
				{
					in_array($fieldName, $skipTimeZoneFields, true) && CTimeZone::Enable();
				}
			}
		}

		return $fields;
	}
}
