<?php

namespace Bitrix\Mobile\Internal\Services\Project;

use Bitrix\Im\V2\Chat\GroupChat;
use Bitrix\Im\V2\Chat\Update\UpdateFields;
use Bitrix\Im\V2\Chat\Update\UpdateService;
use Bitrix\Im\V2\Message\Delete\DisappearService;
use Bitrix\Im\V2\Service\Messenger;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Collab\Control\Command\ValueObject\CollabOptions;
use Bitrix\Socialnetwork\Collab\Control\Option\Command\SetOptionsCommand;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\AllowGuestsInvitationField;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\CanGuestCopyTextOption;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\CanGuestScreenshotOption;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\ManageMessagesAutoDelete;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\ManageMessagesOption;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\MessagesAutoDeleteDelay;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\ShowHistoryOption;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\WhoCanInviteOption;
use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Socialnetwork\Integration\Im\Chat\Workgroup;

Loc::loadMessages(__FILE__);

final class ProjectChatSettingsService
{
	public function __construct(
		private readonly ProjectSettingsValidator $validator,
		private readonly OperationResultErrorResolver $errorResolver,
	)
	{
	}

	public function apply(
		int $projectId,
		?string $messageWriters = null,
		?string $showHistory = null,
		?int $messagesAutoDeleteDelay = null,
	): void
	{
		$this->validator->validateChatSettings($messageWriters, $showHistory, $messagesAutoDeleteDelay);

		$chat = $this->getOrCreateProjectChat($projectId);

		if ($messageWriters !== null)
		{
			$manageMessagesRight = $this->validator->mapGroupRoleToChatRight($messageWriters);

			$updateResult = (new UpdateService(
				$chat,
				UpdateFields::create([
					'MANAGE_MESSAGES' => $manageMessagesRight,
				]),
			))->updateChat();

			if (!$updateResult->isSuccess())
			{
				throw new \RuntimeException($this->errorResolver->resolve(
					$updateResult,
					Loc::getMessage('MOBILE_PROJECT_CHAT_SETTINGS_SERVICE_CHAT_UNAVAILABLE'),
				));
			}
		}

		if ($showHistory !== null)
		{
			$showHistoryResult = $this->setShowHistory($projectId, $showHistory);
			if (!$showHistoryResult->isSuccess())
			{
				throw new \RuntimeException($this->errorResolver->resolve(
					$showHistoryResult,
					Loc::getMessage('MOBILE_PROJECT_CHAT_SETTINGS_SERVICE_INCORRECT_SHOW_HISTORY'),
				));
			}
		}

		if ($messagesAutoDeleteDelay !== null)
		{
			$updateAutoDeleteResult = DisappearService::disappearChat(
				$chat,
				$messagesAutoDeleteDelay,
			);

			if (!$updateAutoDeleteResult->isSuccess())
			{
				throw new \RuntimeException($this->errorResolver->resolve(
					$updateAutoDeleteResult,
					Loc::getMessage('MOBILE_PROJECT_CHAT_SETTINGS_SERVICE_INCORRECT_AUTO_DELETE_DELAY'),
				));
			}
		}
	}

	public function getSettings(int $projectId): array
	{
		$chat = $this->getOrCreateProjectChat($projectId);

		return [
			'messageWriters' => $this->validator->mapChatRightToGroupRole((string)$chat->getManageMessages())
				?? SONET_ROLES_USER,
			'showHistory' => $this->getShowHistory($projectId),
			'messagesAutoDeleteDelay' => $chat->getMessagesAutoDeleteDelay(),
		];
	}

	public function getCurrentOptions(int $projectId): array
	{
		$chat = $this->getOrCreateProjectChat($projectId);

		return [
			ManageMessagesOption::NAME => $this->mapChatRightToGroupRoleOrDefault(
				(string)$chat->getManageMessages(),
				ManageMessagesOption::DEFAULT_VALUE,
			),
			WhoCanInviteOption::NAME => $this->mapChatRightToGroupRoleOrDefault(
				(string)$chat->getManageUsersAdd(),
				WhoCanInviteOption::DEFAULT_VALUE,
			),
			ShowHistoryOption::NAME => $this->getOptionValueOrDefault(
				$projectId,
				ShowHistoryOption::DB_NAME,
				ShowHistoryOption::DEFAULT_VALUE,
			),
			MessagesAutoDeleteDelay::NAME => $this->getMessagesAutoDeleteDelayOptionValue(
				$projectId,
				$chat->getMessagesAutoDeleteDelay(),
			),
			ManageMessagesAutoDelete::NAME => $this->mapChatRightToGroupRoleOrDefault(
				(string)$chat->getManageMessagesAutoDelete(),
				ManageMessagesAutoDelete::DEFAULT_VALUE,
			),
			CanGuestCopyTextOption::NAME => $this->getOptionValueOrDefault(
				$projectId,
				CanGuestCopyTextOption::DB_NAME,
				CanGuestCopyTextOption::DEFAULT_VALUE,
			),
			CanGuestScreenshotOption::NAME => $this->getOptionValueOrDefault(
				$projectId,
				CanGuestScreenshotOption::DB_NAME,
				CanGuestScreenshotOption::DEFAULT_VALUE,
			),
			AllowGuestsInvitationField::NAME => $this->getOptionValueOrDefault(
				$projectId,
				AllowGuestsInvitationField::DB_NAME,
				AllowGuestsInvitationField::DEFAULT_VALUE,
			),
		];
	}

	public function ensureChatId(int $projectId): int
	{
		return $this->getOrCreateProjectChat($projectId)->getChatId() ?? 0;
	}

	private function setShowHistory(int $projectId, string $showHistory): Result
	{
		$command = (new SetOptionsCommand())
			->setCollabId($projectId)
			->setOptions(new CollabOptions(new ShowHistoryOption($showHistory)));

		$service = ServiceLocator::getInstance()->get('socialnetwork.collab.option.service');

		return $service->set($command);
	}

	private function getShowHistory(int $projectId): string
	{
		return $this->getOptionValueOrDefault(
			$projectId,
			ShowHistoryOption::DB_NAME,
			ShowHistoryOption::DEFAULT_VALUE,
		);
	}

	private function getOptionValueOrDefault(int $projectId, string $optionName, string $defaultValue): string
	{
		$optionValue = CollabRegistry::getInstance()->get($projectId)?->getOptionValue($optionName);

		return is_string($optionValue) ? $optionValue : $defaultValue;
	}

	private function getMessagesAutoDeleteDelayOptionValue(int $projectId, int $chatDelay): string
	{
		$optionValue = CollabRegistry::getInstance()->get($projectId)?->getOptionValue(MessagesAutoDeleteDelay::DB_NAME);

		return is_string($optionValue) ? $optionValue : (string)$chatDelay;
	}

	private function mapChatRightToGroupRoleOrDefault(string $chatRight, string $defaultRole): string
	{
		return $this->validator->mapChatRightToGroupRole($chatRight) ?? $defaultRole;
	}

	private function getOrCreateProjectChat(int $projectId): GroupChat
	{
		$chatData = Workgroup::getChatData([
			'group_id' => $projectId,
			'skipAvailabilityCheck' => true,
		]);
		$chatId = (int)($chatData[$projectId] ?? 0);
		if ($chatId <= 0)
		{
			Workgroup::createChat([
				'group_id' => $projectId,
			]);

			$chatData = Workgroup::getChatData([
				'group_id' => $projectId,
				'skipAvailabilityCheck' => true,
			]);

			$chatId = (int)($chatData[$projectId] ?? 0);
			if ($chatId <= 0)
			{
				throw new \RuntimeException(Loc::getMessage('MOBILE_PROJECT_CHAT_SETTINGS_SERVICE_CHAT_NOT_FOUND'));
			}
		}

		$chat = Messenger::getInstance()?->getChat($chatId);
		if (!($chat instanceof GroupChat))
		{
			throw new \RuntimeException(Loc::getMessage('MOBILE_PROJECT_CHAT_SETTINGS_SERVICE_CHAT_UNAVAILABLE'));
		}

		return $chat;
	}
}
