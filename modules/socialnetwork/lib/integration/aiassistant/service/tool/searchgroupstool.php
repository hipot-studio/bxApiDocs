<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Integration\AiAssistant\Service\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Main\Web\Json;
use Bitrix\Socialnetwork\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Socialnetwork\Integration\AiAssistant\Service\Dto\SearchGroupsDto;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\V2\Internal\Service\ProjectOptionService;
use CSocNetGroup;
use CSocNetUser;

class SearchGroupsTool extends BaseTool
{
	public const ACTION_NAME = 'search_groups';
	public const LIMIT = 10;

	public function __construct(
		private readonly ProjectOptionService $projectOptionService,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getName(): string
	{
		return self::ACTION_NAME;
	}

	public function getDescription(): string
	{
		return
			'Searches for groups. Returns their identifiers, names and types. '
			. 'NOTATION: when a result row contains a "DISPLAY_TYPE" field, '
			. 'you MUST use DISPLAY_TYPE — not TYPE — whenever you mention this group to the user.'
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'title' => [
					'type' => ['string', 'null'],
					'description' => 'Group title to search or null if not needed.',
				],
				'description' => [
					'type' => ['string', 'null'],
					'description' => 'Group description to search or null if not needed.',
				],
				'type' => [
					'type' => ['string', 'null'],
					'description' =>
						'The type of group. '
						. 'Must be one of "' . implode('", "', Type::values())
						. '" or null if not needed.'
					,
					'enum' =>[...Type::values(), null]
				],
				'ownerId' => [
					'type' => ['integer', 'null'],
					'description' =>
						'Identifier of the user who owns the group. '
						. 'Must be a positive integer or null if not needed.'
					,
					'minimum' => 1,
				],
			],
			'additionalProperties' => false,
		];
	}

	/**
	 * @throws ArgumentException
	 * @throws DtoValidationException
	 */
	protected function execute(int $userId, ...$args): string
	{
		$dto = SearchGroupsDto::fromArray($args);

		$this->validate($dto);

		$groups = $this->searchGroups($dto, $userId);

		if (empty($groups))
		{
			return 'Groups not found.';
		}

		return 'Groups successfully found: ' . Json::encode($groups) . '.';
	}

	protected function searchGroups(SearchGroupsDto $dto, int $userId): array
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		$filter = [
			'ACTIVE' => 'Y',
			'VISIBLE' => 'Y',
		];

		if (!CSocNetUser::isUserModuleAdmin($userId))
		{
			$filter['CHECK_PERMISSIONS'] = $userId;
		}

		if ($dto->title !== null)
		{
			$filter['NAME'] = $dto->title;
		}
		if ($dto->description !== null)
		{
			$filter['DESCRIPTION'] = $dto->description;
		}
		if ($dto->type !== null)
		{
			$filter['TYPE'] = $dto->type->value;
		}
		if ($dto->ownerId !== null)
		{
			$filter['OWNER_ID'] = $dto->ownerId;
		}

		$groups = [];

		$result = CSocNetGroup::getList(
			arFilter: $filter,
			arNavStartParams: ['nTopCount' => self::LIMIT],
			arSelectFields: ['ID', 'NAME', 'DESCRIPTION', 'TYPE', 'OWNER_ID'],
		);

		while ($group = $result->fetch())
		{
			$groups[] = $group;
		}

		return $this->appendDisplayType($groups);
	}

	private function appendDisplayType(array $groups): array
	{
		if ($groups === [] || !Feature::isNewProjectsOn())
		{
			return $groups;
		}

		$collabIds = [];
		foreach ($groups as $group)
		{
			$id = (int)($group['ID'] ?? 0);
			$type = (string)($group['TYPE'] ?? '');

			if ($id > 0 && $type === Type::Collab->value)
			{
				$collabIds[] = $id;
			}
		}

		if ($collabIds === [])
		{
			return $groups;
		}

		$convertedMap = $this->projectOptionService->isCollabConvertedBatch($collabIds);

		foreach ($groups as $key => $group)
		{
			$id = (int)($group['ID'] ?? 0);

			if ($id > 0 && ($convertedMap[$id] ?? false))
			{
				$groups[$key]['DISPLAY_TYPE'] = Type::Project->value;
			}
		}

		return $groups;
	}
}
