<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class ResolveAccessSubjectTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'resolve_access_subject';
	}

	public function getDescription(): string
	{
		return 'Finds Bitrix access subjects by natural-language query before folder access changes. Returns accessCode candidates for users, departments and workgroups, reports ambiguous matches and limitations when a source module is unavailable. Use it before manage_folder_access unless the user provided an exact accessCode.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'query' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'User, department, workgroup, or an exact access code. Free-text user name searches require at least 3 characters; exact user codes like U123 are accepted.',
				],
				'subjectTypes' => [
					'type' => 'array',
					'items' => [
						'type' => 'string',
						'enum' => ['user', 'department', 'socialnetwork_group'],
					],
					'description' => 'Optional subject type filter.',
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 10,
				],
			],
			'required' => ['query'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->resolveAccessSubjects(
			$userId,
			$this->stringArg($args, 'query') ?? '',
			(array)($args['subjectTypes'] ?? []),
			$this->limitArg($args),
		);
	}
}
