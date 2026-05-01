<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Prepare\Add;

use Bitrix\Main\ObjectException;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Control\Handler\TariffFieldHandler;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\CheckUserFields;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\PrepareFields;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\RunBeforeAddEvent;
use Bitrix\Tasks\V2\Internal\Entity;
use CTimeZone;
use Throwable;

class EntityFieldService
{
	public function prepare(Entity\Task $task, AddConfig $config): array
	{
		$mapper = Container::getInstance()->getOrmTaskMapper();
		$fields = $mapper->mapFromEntity($task);

		$fields = (new PrepareFields($config))($fields);
		$fields = (new RunBeforeAddEvent($config))($fields);

		(new CheckUserFields($config))($fields);

		$tariff = new TariffFieldHandler($fields);

		$fields = $tariff->getFields();

		$fields = $this->applyRestrictions($fields);

		$dbFields = $this->getFieldsToDb($fields, $config->getSkipTimeZoneFields());

		return [$mapper->mapToEntity($dbFields), $fields];
	}

	private function applyRestrictions(array $fields): array
	{
		$tariffService = Container::getInstance()->getTariffService();
		if (!$tariffService->isProjectAvailable((int)($fields['GROUP_ID'] ?? 0)))
		{
			unset($fields['GROUP_ID']);
		}

		if (!$tariffService->isStakeholderAvailable())
		{
			unset($fields['ACCOMPLICES'], $fields['AUDITORS']);
		}

		return $fields;
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
