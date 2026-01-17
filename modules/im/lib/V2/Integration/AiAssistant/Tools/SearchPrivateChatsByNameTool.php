<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto\SearchPrivateChatsByNameDto;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Main\Web\Json;
use Throwable;

class SearchPrivateChatsByNameTool extends BaseImTool
{
	private const SEARCH_LIMIT = 100;

	public function getName(): string
	{
		return 'search_private_chats_by_name';
	}

	public function getDescription(): string
	{
		return "Searches for private chats by user name. Returns an array of private chat dialogId and their names. "
			. "This tool works exclusively with private chats and does not search group chats.";
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'query' => [
					'description' => 'The search query. This should be a user name for private chats.',
					'type' => 'string',
					'minLength' => 3,
				],
			],
			'additionalProperties' => false,
			'required' => ['query'],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		/** @var ValidationService $validation */
		$validation = ServiceLocator::getInstance()->get('main.validation.service');

		$searchPrivateChatsDto = SearchPrivateChatsByNameDto::createFromParams($args);
		$validationResult = $validation->validate($searchPrivateChatsDto);

		if (!$validationResult->isSuccess())
		{
			return "Invalid search query provided. Please refine your request and try again.";
		}

		$chats = $this->findPrivateChats($searchPrivateChatsDto, $userId);
		return $this->formatResult($chats);
	}

	private function findPrivateChats(SearchPrivateChatsByNameDto $searchPrivateChatsDto, int $currentUserId): array
	{
		$currentUser = User::getInstance($currentUserId);
		if ($currentUser->isExtranet())
		{
			return [];
		}

		$users = UserCollection::findByName($searchPrivateChatsDto->query, [$currentUserId], self::SEARCH_LIMIT);

		return $this->formatUserResults($users);
	}

	private function formatResult(array $chats): string
	{
		if (empty($chats))
		{
			return "Chats matching the specified query were not found. Please try modifying your search query.";
		}

		try
		{
			return Json::encode($chats);
		}
		catch (Throwable $e)
		{
			return "Error: Could not format the search result.";
		}

	}

	private function formatUserResults(UserCollection $users): array
	{
		$resultArray = [];

		foreach ($users as $user)
		{
			$resultArray[] = [
				'dialogId' => (string)$user->getId(),
				'title' => $user->getName(),
			];
		}

		return $resultArray;
	}
}
