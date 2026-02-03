<?php

namespace Bitrix\Im\V2\Chat\EntityLink;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\EntityLink;
use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\CreateEntityLinkEvent;
use Bitrix\Main\Loader;

class EntityLinkFactory
{
	private static self $instance;

	public function create(Chat $chat): EntityLink
	{
		return $this->initInternalLink($chat)
			?? (($chat instanceof ExternalChat) ? $this->initExternalLink($chat) : null)
			?? new EntityLink($this->createEntityLinkDto($chat));
	}

	public static function getInstance(): self
	{
		if (isset(self::$instance))
		{
			return self::$instance;
		}

		self::$instance = new static();

		return self::$instance;
	}

	private function initInternalLink(Chat $chat): ?EntityLink
	{
		$type = $chat->getEntityType() ?? '';
		$entityLinkDto = $this->createEntityLinkDto($chat);

		return match (true)
		{
			($type === ExtendedType::Sonet->value && Loader::includeModule('socialnetwork')) => new SonetType($entityLinkDto),
			($type === ExtendedType::Calendar->value && Loader::includeModule('calendar')) => new CalendarType($entityLinkDto),
			($type === ExtendedType::Crm->value && Loader::includeModule('crm')) => new CrmType($entityLinkDto),
			($type === ExtendedType::Call->value && Loader::includeModule('crm')) => new CallType($entityLinkDto, $chat->getEntityData1()),
			($type === ExtendedType::Mail->value && Loader::includeModule('mail')) => new MailType($entityLinkDto),
			($type === ExtendedType::Tasks->value && Loader::includeModule('tasks')) => new TasksType($entityLinkDto),
			// TODO: replace with event
			(Loader::includeModule('tasks') && $type === \Bitrix\Tasks\V2\Internal\Integration\Im\Chat::ENTITY_TYPE ) => new TasksType($entityLinkDto),
			default => null,
		};
	}

	private function initExternalLink(ExternalChat $chat): ?EntityLink
	{
		$event = new CreateEntityLinkEvent($chat);
		$event->send();

		$entityLinkDto = $event->getEntityLinkDto();

		return isset($entityLinkDto) ? new EntityLink($entityLinkDto) : null;
	}

	private function createEntityLinkDto(Chat $chat): EntityLinkDto
	{
		$type = $chat->getEntityType() ?? '';
		$chatId = $chat->getChatId() ?? 0;
		$entityId = $chat->getEntityId() ?? '';

		return new EntityLinkDto($type, $chatId, $entityId);
	}
}
