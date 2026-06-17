<?php

namespace Bitrix\BIConnector\Public\Command\DashboardChat;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardChat;
use Bitrix\BIConnector\Internal\Integration\Im\DashboardDiscussionChatService;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardChatRepository;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoRepository;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Repository\Exception\PersistenceException;

class GetOrCreateDashboardDiscussionChatCommandHandler
{
	private SupersetDashboardChatRepository $dashboardChatRepository;
	private SupersetDashboardInfoRepository $dashboardInfoRepository;
	private DashboardDiscussionChatService $dashboardDiscussionChatService;
	private DashboardDiscussionChatResult $dashboardDiscussionChatResult;

	public function __construct()
	{
		$this->dashboardChatRepository = ServiceLocator::getInstance()->get(SupersetDashboardChatRepository::class);
		$this->dashboardInfoRepository = ServiceLocator::getInstance()->get(SupersetDashboardInfoRepository::class);
		$this->dashboardDiscussionChatService = ServiceLocator::getInstance()->get('biconnector.service.dashboardDiscussionChat');
		$this->dashboardDiscussionChatResult = new DashboardDiscussionChatResult();
	}

	public function __invoke(GetOrCreateDashboardDiscussionChatCommand $command): DashboardDiscussionChatResult
	{
		try
		{
			$linkedDashboardChat = $this->dashboardChatRepository->getByDashboardId($command->dashboardId);
			if ($linkedDashboardChat && $linkedDashboardChat->getChatId() > 0)
			{
				$linkedChatId = (int)$linkedDashboardChat->getChatId();
				$addUsersResult = $this->dashboardDiscussionChatService->addUsersToChat(
					$linkedChatId,
					[$command->currentUserId],
				);
				if (!$addUsersResult->isSuccess())
				{
					$this->dashboardDiscussionChatResult->addErrors($addUsersResult->getErrors());

					return $this->dashboardDiscussionChatResult;
				}

				return $this->dashboardDiscussionChatResult
					->setChatId($linkedChatId)
					->setDialogId("chat{$linkedChatId}")
					->setIsCreated(false)
				;
			}

			$createChatResult = $this->dashboardDiscussionChatService->createOrGetUniqueChat(
				dashboardId: $command->dashboardId,
				dashboardTitle: $command->dashboardTitle,
				authorId: $command->currentUserId,
				initialUserIds: $this->getInitialUserIds($command),
			);
			if (!$createChatResult->isSuccess())
			{
				$this->dashboardDiscussionChatResult->addErrors($createChatResult->getErrors());

				return $this->dashboardDiscussionChatResult;
			}

			$chatId = (int)($createChatResult->getData()['chatId'] ?? 0);
			if ($chatId <= 0)
			{
				$this->dashboardDiscussionChatResult->addError(
					new Error(
						Loc::getMessage(
							'BICONNECTOR_COMMAND_GET_OR_CREATE_DASHBOARD_DISCUSSION_CHAT_COMMAND_HANDLER_ERROR_CHAT_NOT_FOUND',
						),
					),
				);

				return $this->dashboardDiscussionChatResult;
			}

			$addUsersResult = $this->dashboardDiscussionChatService->addUsersToChat($chatId, [$command->currentUserId]);
			if (!$addUsersResult->isSuccess())
			{
				$this->dashboardDiscussionChatResult->addErrors($addUsersResult->getErrors());

				return $this->dashboardDiscussionChatResult;
			}

			$dashboardChat = $this->saveDashboardChatLink($command->dashboardId, $chatId, $command->currentUserId);
			if ($dashboardChat->getChatId() > 0)
			{
				$chatId = $dashboardChat->getChatId();
			}

			return $this->dashboardDiscussionChatResult
				->setChatId($chatId)
				->setDialogId("chat{$chatId}")
				->setIsCreated((bool)($createChatResult->getData()['isCreated'] ?? false))
			;
		}
		catch (\Exception $e)
		{
			$this->dashboardDiscussionChatResult->addError(
				new Error($e->getMessage(), $e->getCode()),
			);
		}

		return $this->dashboardDiscussionChatResult;
	}

	private function saveDashboardChatLink(int $dashboardId, int $chatId, int $createdById): SupersetDashboardChat
	{
		$existingDashboardChat = $this->dashboardChatRepository->getByDashboardId($dashboardId);
		if ($existingDashboardChat)
		{
			return $existingDashboardChat;
		}

		$dashboardChat = new SupersetDashboardChat($dashboardId, $chatId, $createdById);

		try
		{
			$this->dashboardChatRepository->save($dashboardChat);
		}
		catch (PersistenceException $e)
		{
			$existingDashboardChat = $this->dashboardChatRepository->getByDashboardId($dashboardId);
			if ($existingDashboardChat)
			{
				return $existingDashboardChat;
			}

			throw $e;
		}

		return $dashboardChat;
	}

	private function getInitialUserIds(GetOrCreateDashboardDiscussionChatCommand $command): array
	{
		$userIds = [$command->currentUserId];

		$dashboardCreatedById = $command->dashboardCreatedById;
		if ($dashboardCreatedById > 0)
		{
			$userIds[] = $dashboardCreatedById;
		}

		$dashboardInfo = $this->dashboardInfoRepository->getByDashboardId($command->dashboardId);
		$dashboardUpdatedById = (int)($dashboardInfo?->getUpdatedById() ?? 0);
		if ($dashboardUpdatedById > 0)
		{
			$userIds[] = $dashboardUpdatedById;
		}

		return array_values(array_unique($userIds));
	}
}
