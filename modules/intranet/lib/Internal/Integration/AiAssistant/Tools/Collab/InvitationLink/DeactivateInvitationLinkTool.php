<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\InvitationLink;

use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab\ProjectIdDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\BaseCollabTool;
use Bitrix\Intranet\Service\ServiceContainer as IntranetServiceContainer;

class DeactivateInvitationLinkTool extends BaseCollabTool
{
	public function canRun(int $userId): bool
	{
		return parent::canRun($userId) && $this->isRegisterByLinkAllowed();
	}

	public function getName(): string
	{
		return 'deactivate_invitation_link';
	}

	public function getDescription(): string
	{
		return
			'Deactivates the current project invitation link so that link-based joining stops working. '
			. 'If the project link is already inactive, the tool returns active=false without creating a new link.'
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
		$this->resolveProject($userId, $dto->projectId);

		$linkEntity = $this->getInvitationLinkEntity($dto->projectId);
		$deactivated = false;
		if ($linkEntity !== null && $linkEntity->getId() !== null)
		{
			$deactivated = $this->deleteInvitationLink((int)$linkEntity->getId());
		}

		return [
			'projectId' => $dto->projectId,
			'active' => false,
			'deactivated' => $deactivated,
		];
	}

	protected function deleteInvitationLink(int $linkId): bool
	{
		return IntranetServiceContainer::getInstance()
			->invitationLinkRepository()
			->delete($linkId)
		;
	}
}
