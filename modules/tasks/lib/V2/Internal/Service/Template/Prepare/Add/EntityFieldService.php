<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Prepare\Add;

use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\CheckUserFields;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\PrepareFields;

class EntityFieldService
{
	private const DEPRECATED_FIELDS = [
		'ACCOMPLICES',
		'AUDITORS',
		'RESPONSIBLES',
		'TAGS',
		'DEPENDS_ON',
		'PARAMS',
	];

	public function prepare(Entity\Template $template, AddConfig $config): array
	{
		$mapper = Container::getInstance()->getOrmTemplateMapper();

		$fields = $mapper->mapFromEntity($template);

		$fields = (new PrepareFields())($fields);

		(new CheckUserFields($config))($fields);

		$dbFields = $this->getFieldsToDb($fields);

		return [$mapper->mapToEntity($dbFields), $fields];
	}

	public function getFieldsToDb(array $fields): array
	{
		$tableFields = TemplateTable::getEntity()->getFields();

		foreach ($fields as $fieldName => $value)
		{
			if (!array_key_exists($fieldName, $tableFields))
			{
				unset($fields[$fieldName]);
				continue;
			}

			if (in_array($fieldName, self::DEPRECATED_FIELDS))
			{
				unset($fields[$fieldName]);
				continue;
			}

			if (str_starts_with($fieldName, "UF_"))
			{
				unset($fields[$fieldName]);
				continue;
			}

			if (
				$tableFields[$fieldName] instanceof DatetimeField
				&& !empty($value)
			)
			{
				$fields[$fieldName] = DateTime::createFromUserTime($value);
			}

			if (is_array($value))
			{
				$fields[$fieldName] = serialize($value);
			}
		}

		return $fields;
	}
}
