<?php

namespace Bitrix\Im\V2\Chat\ExternalChat;

use Bitrix\Im\V2\Common\Event\BaseEvent;
use Bitrix\Main\Engine\Response\Converter;

abstract class Event extends BaseEvent
{
	private const TYPE_TEMPLATE = 'On#ActionName#ExternalChat#EntityType#';

	public function __construct(string $entityType, array $parameters)
	{
		parent::__construct($this->formatType($entityType), $parameters);
	}

	abstract protected function getActionName(): string;

	protected function formatType(string $entityType): string
	{
		$converter = new Converter(Converter::TO_CAMEL | Converter::UC_FIRST);
		$entityTypeInCamelCase = $converter->process($entityType);
		$actionName = $this->getActionName();

		return strtr(self::TYPE_TEMPLATE, ['#ActionName#' => $actionName, '#EntityType#' => $entityTypeInCamelCase]);
	}
}