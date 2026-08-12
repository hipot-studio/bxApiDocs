<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\InvitationLink;

use Bitrix\Intranet\Infrastructure\LinkCodeGenerator;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab\ProjectIdDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\BaseCollabTool;

class ActivateInvitationLinkTool extends BaseCollabTool
{
	public function canRun(int $userId): bool
	{
		return
			parent::canRun($userId)
			&& $this->isRegisterByLinkAllowed()
			&& $this->isInvitationAdmissionAllowed()
		;
	}

	public function getName(): string
	{
		return 'activate_invitation_link';
	}

	public function getDescription(): string
	{
		return
			'Activates the project invitation link if it is currently inactive and returns the resulting URL. '
			. 'If the link is already active, the current active link is returned without rotation.'
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
		$linkEntity = $this->getInvitationLinkEntity($dto->projectId);
		$activated = false;

		if ($linkEntity === null)
		{
			$linkEntity = $this->generateInvitationLinkEntity($dto->projectId);
			$activated = true;
		}

		return [
			'projectId' => $dto->projectId,
			'active' => true,
			'activated' => $activated,
			...$this->buildInvitationLinkUrls($userId, $project, $linkEntity->getCode()),
		];
	}

	protected function generateInvitationLinkEntity(int $projectId)
	{
		return LinkCodeGenerator::createByCollabId($projectId)->generate();
	}
}
