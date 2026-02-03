<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\UserProvider;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Member\SearchUsersDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\MemberSchemaBuilder;

// TODO: This is the general logic. Remove when there is an analog in AiAssistant.
class SearchUsersTool extends BaseTool
{
	public const ACTION_NAME = 'search_users';

	public function __construct(
		private readonly UserProvider $userProvider,
		MemberSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($schemaBuilder, $validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return
			'Searches for a SINGLE user to get their ID by trying a list of possible name variations. '
			. 'Use this to find one user\'s ID, which can then be used in other tools.'
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'searchQueries' => [
					'type' => 'array',
					'items' => [
						'type' => 'string',
						'description' => 'A single name variation for the search.',
						'minLength' => 2,
					],
					'description' =>
						'A list of name variations for the user you are trying to find. '
						. 'Provide different spellings, diminutive forms, or transliterations.'
					,
					'minItems' => 1,
				],
			],
			'required' => ['searchQueries'],
			'additionalProperties' => false,
		];
	}

	protected function execute(int $userId, ...$args): string
	{
		$dto = SearchUsersDto::fromArray($args);

		try
		{
			$this->validate($dto);
		}
		catch (DtoValidationException $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}

		$users = $this->userProvider->search($dto);

		if (empty($users))
		{
			return 'Users not found.';
		}

		return 'Users successfully found: ' . Json::encode($users) . '.';
	}
}
