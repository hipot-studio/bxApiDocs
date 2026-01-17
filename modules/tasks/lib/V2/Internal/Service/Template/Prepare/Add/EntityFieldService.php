<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Prepare\Add;

use Bitrix\Tasks\Control\Handler\TemplateFieldHandler;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\CheckUserFields;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\PrepareFields;

class EntityFieldService
{
	public function prepare(Entity\Template $template, AddConfig $config): array
	{
		$mapper = Container::getInstance()->getOrmTemplateMapper();

		$fields = $mapper->mapFromEntity($template);

		$fields = (new PrepareFields())($fields);

		(new CheckUserFields($config))($fields);

		$handler = new TemplateFieldHandler($config->getUserId(), $fields);

		$dbFields = $handler->getFieldsToDb();

		return [$mapper->mapToEntity($dbFields), $fields];
	}
}
