<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Prepare\Update;

use Bitrix\Tasks\Control\Handler\TemplateFieldHandler;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\CheckUserFields;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\PrepareFields;

class EntityFieldService
{
	public function prepare(Entity\Template $template, UpdateConfig $config, array $currentTemplate): array
	{
		$mapper = Container::getInstance()->getOrmTemplateMapper();

		$fields = $mapper->mapFromEntity($template);

		$fields = (new PrepareFields())($fields, $currentTemplate);

		(new CheckUserFields($config))($fields, $currentTemplate);

		$handler = new TemplateFieldHandler($config->getUserId(), $fields);

		$dbFields = $handler->getFieldsToDb();

		return [$mapper->mapToEntity($dbFields), $fields];
	}
}
