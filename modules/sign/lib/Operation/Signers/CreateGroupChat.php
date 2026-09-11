<?php

namespace Bitrix\Sign\Operation\Signers;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Result\Service\Integration\Im\CreateGroupChatResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Im\GroupChatService;
use Bitrix\Sign\Service\SignersListService;
use Bitrix\Sign\Service\UserService;

class CreateGroupChat implements Contract\Operation
{
	private SignersListService $signersListService;
	private GroupChatService $groupChatService;
	private UserService $userService;

	public function __construct(
		private readonly int $signersListId,
		private readonly int $chatOwnerId,
		?SignersListService $signersListService = null,
		?GroupChatService $groupChatService = null,
		?UserService $userService = null,
	)
	{
		$this->signersListService = $signersListService ?? Container::instance()->getSignersListService();
		$this->groupChatService = $groupChatService ?? Container::instance()->getGroupChatService();
		$this->userService = $userService ?? Container::instance()->getUserService();
	}

	public function launch(): Main\Result|CreateGroupChatResult
	{
		$chatParams = $this->configureChatParams();

		$createResult = $this
			->groupChatService
			->createChat($chatParams['chatData'])
		;
		if (!$createResult instanceOf CreateGroupChatResult)
		{
			return $createResult;
		}

		return new CreateGroupChatResult($createResult->chatId, $chatParams['warning'] ?? null);
	}

	/**
	 * @return  array{chatData: array{USERS: int[], AUTHOR_ID: int}, warning: string}
	 */
	private function configureChatParams(): array
	{
		$signersIds =
			$this
				->signersListService
				->listSigners($this->signersListId)->getUserIds()
		;

		$users = [];

		foreach (array_chunk($signersIds, 300) as $signerIdsChunk)
		{
			array_push($users, ...$this->userService->listByIds($signerIdsChunk)->toArray());
		}

		$activeIntranetUsers = array_filter(
			$users,
			fn(\Bitrix\Sign\Item\User $user): bool =>
				$user->isActive && \Bitrix\Sign\Config\User::instance()->canUserParticipateInSigning($user->id)
		);

		$activeIntranetUserIds = array_map(fn(\Bitrix\Sign\Item\User $user): int => $user->id, $activeIntranetUsers);

		if (!in_array($this->chatOwnerId, $activeIntranetUserIds, true))
		{
			$activeIntranetUserIds[] = $this->chatOwnerId;
			$signersIds[] = $this->chatOwnerId;
		}

		$data = [
			'chatData' => [
				'USERS' => $activeIntranetUserIds,
				'AUTHOR_ID' => $this->chatOwnerId,
			]
		];

		$skippedUsers = array_diff($signersIds, $activeIntranetUserIds);
		$skippedUsersCount = count($skippedUsers);

		if ($skippedUsersCount !== 0)
		{
			$data['warning'] = Loc::getMessagePlural(
				'SIGN__B2E_SIGNERS_LIST_CREATE_GROUP_CHAT_SKIP_NOT_INTRANET_USERS_WARNING',
				$skippedUsersCount,
				[
					'#COUNT#' => $skippedUsersCount,
				]
			);
		}

		return $data;
	}
}