<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\LegacyGroupAccessService;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Dto\GetProjectForAnalysisDto;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Service\ProjectService;

class GetProjectForAnalysisTool extends BaseTool
{
	public const NAME = 'get_project_for_analysis';

	public function __construct(
		private readonly ProjectService $projectService,
		private readonly LegacyGroupAccessService $groupAccessService,
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
			'Returns project metadata for analysis: projectId, name, '
			. 'type (one of: ' . implode(', ', Type::values()) . '), '
			. 'stages (list of kanban stage names), link. '
			. 'NOTATION: if the response contains a "displayType" field, '
			. 'you MUST use displayType — not type — whenever you mention this project to the user. '
			. 'Requires projectId. '
			. 'When projectId is unknown, call ' . FindProjectByNameTool::NAME . ' first to resolve it.'
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'projectId' => [
					'type' => 'integer',
					'description' => 'Identifier of the project to analyze',
					'minimum' => 1,
				],
			],
			'required' => ['projectId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = GetProjectForAnalysisDto::fromArray($args);

		$this->validate($dto);

		$projectId = (int)$dto->projectId;

		if (!$this->groupAccessService->canRead($userId, $projectId))
		{
			throw new McpException("Access denied to project {$projectId}");
		}

		$project = $this->projectService->getForAnalysis($projectId, $userId);
		if ($project === null)
		{
			throw new McpException("Project {$projectId} not found. It may have been deleted");
		}

		return $project;
	}
}
