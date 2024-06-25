<?php

namespace Bitrix\Tasks\Flow\Integration\Socialnetwork;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Component\WorkgroupForm;
use Bitrix\Socialnetwork\Helper\Workgroup;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Tasks\Flow\Control\Exception\InvalidCommandException;
use Bitrix\Tasks\Flow\Integration\Socialnetwork\Exception\AutoCreationException;
use Bitrix\Tasks\Flow\Kanban\KanbanCommand;
use Bitrix\Tasks\Flow\Kanban\KanbanService;
use CSocNetFeatures;
use CSocNetGroup;
use CSocNetGroupSubject;
use CSocNetUserToGroup;
use RuntimeException;

class GroupService
{
	protected GroupCommand $command;
	protected KanbanService $kanbanService;

	public function __construct()
	{
		$this->init();
	}

	public static function getDefaultSubjectId(): int
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			throw new RuntimeException('Socialnetwork is not loaded');
		}

		$subject = CSocNetGroupSubject::GetList(
			['SORT' => 'ASC', 'NAME' => 'ASC'],
			['SITE_ID' => SITE_ID],
			false,
			false,
			['ID', 'NAME'],
		)->fetch();

		return (int)($subject['ID'] ?? 0);
	}

	public static function getDefaultAvatar(): string
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			throw new RuntimeException('Socialnetwork is not loaded');
		}

		return array_key_first(Workgroup::getDefaultAvatarTypes());
	}

	/**
	 * @throws AutoCreationException
	 * @throws RuntimeException
	 * @throws InvalidCommandException
	 */
	public function add(GroupCommand $command): int
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			throw new RuntimeException('Socialnetwork is not loaded');
		}

		$this->command = $command;

		$command->validateAdd();

		$this->saveGroup();

		$this->saveMembers();

		$this->saveFeatures();

		$this->installStages();

		return $this->command->id;
	}

	/**
	 * @throws AutoCreationException
	 */
	protected function saveGroup(): void
	{
		$groupId = CSocNetGroup::createGroup($this->command->ownerId, [
			'NAME' => $this->command->name,
			'SITE_ID' => SITE_ID,
			'SUBJECT_ID' => static::getDefaultSubjectId(),
			'INITIATE_PERMS' => UserToGroupTable::ROLE_USER,
			'AVATAR_TYPE' => static::getDefaultAvatar(),
		]);

		if ($groupId === false)
		{
			throw new AutoCreationException(Loc::getMessage('TASKS_FLOW_GROUP_SERVICE_CANNOT_AUTO_CREATE_GROUP'));
		}

		$this->command->id = $groupId;
	}

	protected function saveMembers(): void
	{
		$userIds = array_filter($this->command->members, fn(int $userId): bool => $userId !== $this->command->ownerId);
		$userIds = array_map('intval', $userIds);
		$userIds = array_unique($userIds);

		CSocNetUserToGroup::AddUsersToGroup(
			$this->command->id,
			$userIds
		);
	}

	/**
	 * @throws AutoCreationException
	 */
	protected function saveFeatures(): void
	{
		$features = [];
		WorkgroupForm::processWorkgroupFeatures(0, $features);

		foreach ($features as $featureName => $featureData)
		{
			$result = CSocNetFeatures::setFeature(
				SONET_ENTITY_GROUP,
				$this->command->id,
				$featureName,
				$featureData['Active'],
			);

			if ($result === false)
			{
				global $APPLICATION;
				$message = $APPLICATION->GetException();
				if ($message)
				{
					throw new AutoCreationException($message->getString());
				}
			}
		}
	}

	protected function installStages(): void
	{
		$kanbanCommand = new KanbanCommand(
			$this->command->id,
			$this->command->ownerId
		);

		/** @var KanbanService $service */
		$service = ServiceLocator::getInstance()->get('tasks.flow.kanban.service');
		$service->add($kanbanCommand);
	}

	protected function init(): void
	{
		$this->kanbanService = new KanbanService();
	}
}