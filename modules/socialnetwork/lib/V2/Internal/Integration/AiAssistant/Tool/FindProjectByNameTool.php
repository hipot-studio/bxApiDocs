<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Dto\FindProjectByNameDto;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Service\ProjectService;

class FindProjectByNameTool extends BaseTool
{
	public const NAME = 'find_project_by_name';

	public function __construct(
		private readonly ProjectService $projectService,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getName(): string
	{
		return self::NAME;
	}

	public function getDescription(): string
	{
		return
			'Finds projects by name. '
			. 'Returns up to ' . ProjectService::SEARCH_LIMIT . ' candidates and a hasMore flag. '
			. 'Use to resolve projectId before calling tools that require it, e.g. '
			. GetProjectForAnalysisTool::NAME . '. '
			. 'Ask the user to clarify on no/multiple matches, or to refine the name when hasMore is true; '
			. 'do not guess and do not paginate. '
			. 'Each candidate: projectId, name, '
			. 'isUserMember (true if the requesting user is a member of the project), link'
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
						'minLength' => FindProjectByNameDto::MIN_QUERY_LENGTH,
						'maxLength' => FindProjectByNameDto::MAX_QUERY_LENGTH,
					],
					'description' =>
						'Project name variants to try. '
						. 'Use multiple variants to cover spelling, transliteration or wording differences'
					,
					'minItems' => 1,
					'maxItems' => FindProjectByNameDto::MAX_QUERIES,
				],
			],
			'required' => ['searchQueries'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = FindProjectByNameDto::fromArray($args);

		$this->validate($dto);

		return $this->projectService->findByName($dto, $userId);
	}
}
