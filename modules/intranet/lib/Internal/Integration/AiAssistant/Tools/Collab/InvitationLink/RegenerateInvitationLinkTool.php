<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\InvitationLink;

use Bitrix\Intranet\Infrastructure\LinkCodeGenerator;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab\ProjectIdDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\BaseCollabTool;
use Bitrix\Main\Event;

class RegenerateInvitationLinkTool extends BaseCollabTool
{
	public function canRun(int $userId): bool
	{
		return parent::canRun($userId) && $this->isRegisterByLinkAllowed();
	}

	public function getName(): string
	{
		return 'regenerate_invitation_link';
	}

	public function getDescription(): string
	{
		return
			'Regenerates the project invitation link and immediately invalidates the previous one. '
			. 'Use this tool only when the old project link must stop working for everyone who already received it.'
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'projectId' => [
					'type' => 'integer',
					'description' => 'Target project ID.',
					'minimum' => 1,
				],
			],
			'required' => ['projectId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructuredInternal(int $userId, ...$args): array
	{
		$dto = ProjectIdDto::fromArray($args);
		$project = $this->resolveProject($userId, $dto->projectId);
		$linkEntity = $this->generateInvitationLinkEntity($dto->projectId);

		(new Event(
			'intranet',
			'onRegenerateCollabInviteLink',
			[
				'collabId' => $dto->projectId,
				'userId' => $userId,
			],
		))->send();

		return [
			'projectId' => $dto->projectId,
			'active' => true,
			...$this->buildInvitationLinkUrls($userId, $project, $linkEntity->getCode()),
			'regenerated' => true,
		];
	}

	protected function generateInvitationLinkEntity(int $projectId)
	{
		return LinkCodeGenerator::createByCollabId($projectId)->generate();
	}
}
