<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools;

use Bitrix\Im\V2\Chat\Filter;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto\SearchGroupChatsByNameDto;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Main\Web\Json;
use Throwable;

class SearchGroupChatsByNameTool extends BaseImTool
{
	private const SEARCH_CHATS_LIMIT = 100;

	public function getName(): string
	{
		return 'search_group_chats_by_name';
	}

	public function getDescription(): string
	{
		return "Searches for group chats by their name. Returns an array of group chat dialogId and their names. "
			. "This tool works exclusively with group chats and does not search private user chats.";
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'query' => [
					'description' => 'The search query. This can be a group chat name or a user name for private chats.',
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

		$searchGroupChatsDto = SearchGroupChatsByNameDto::createFromParams($args);
		$validationResult = $validation->validate($searchGroupChatsDto);

		if (!$validationResult->isSuccess())
		{
			return "Invalid search query. The query must be between 3 and 500 characters long.";
		}

		$chats = $this->findGroupChats($searchGroupChatsDto, $userId);
		return $this->formatResult($chats);
	}

	private function findGroupChats(SearchGroupChatsByNameDto $searchGroupChatsDto, int $userId): array
	{
		$chats = Filter::init()
			->filterByName($searchGroupChatsDto->query)
			->filterUserIsMember($userId)
			->setLimit(self::SEARCH_CHATS_LIMIT)
			->getTitles()
		;

		return array_map(static function ($chat) {
			return [
				'dialogId' => 'chat' . $chat['ID'],
				'title' => $chat['TITLE'],
			];
		}, $chats);
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
}
