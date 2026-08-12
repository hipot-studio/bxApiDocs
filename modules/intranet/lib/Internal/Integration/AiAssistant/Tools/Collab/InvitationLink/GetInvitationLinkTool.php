<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\InvitationLink;

use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab\ProjectIdDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\BaseCollabTool;

class GetInvitationLinkTool extends BaseCollabTool
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
		return 'get_invitation_link';
	}

	public function getDescription(): string
	{
		return
			'Returns the current active invitation link of a project without rotating it. '
			. 'If the link is currently inactive, the tool returns active=false and does not generate a new link.'
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
		if ($linkEntity === null)
		{
			return [
				'projectId' => $dto->projectId,
				'active' => false,
				'shortLink' => null,
				'link' => null,
			];
		}

		return [
			'projectId' => $dto->projectId,
			'active' => true,
			...$this->buildInvitationLinkUrls($userId, $project, $linkEntity->getCode()),
			'createdAt' => $linkEntity->getCreatedAt()?->format(DATE_ATOM),
		];
	}
}
