<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\InviteLink;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Integration\HumanResources\PermissionInvitation;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\InviteLink\CreateInviteLinkDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\BaseTool;
use Bitrix\Intranet\Service\InviteLinkGenerator;

class CreateInviteLinkTool extends BaseTool
{
	public function canRun(int $userId): bool
	{
		return
			parent::canRun($userId)
			&& $this->isRegisterByLinkAllowed()
		;
	}

	public function getName(): string
	{
		return 'create_invite_link';
	}

	public function getDescription(): string
	{
		return
			'Creates a shared invite link for self-registration and rotates the current active shared invite link for the selected departments. '
			. 'Use this tool for link-based onboarding, not for a direct personal invitation. '
			. 'If no department id is specified, the link is created for the default available department. '
			. 'Use shortLink as the primary link for sharing. The link field contains the full URL and should be used as a fallback only. '
			. 'The link is generated with analytics parameters for tracking the source of the invitation. '
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'departmentsId' => [
					'type' => 'array',
					'description' => 'Array of department ids for which the current active shared invite link will be created. If empty, the link for the default available department will be generated.',
					'items' => [
						'type' => 'integer',
					],
				],
			],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		try
		{
			$dto = CreateInviteLinkDto::fromArray($args);
			$departmentCollection = $this->resolveDepartmentCollectionByIds($userId, $dto->departmentsId);
			$departmentsId = $departmentCollection->map(
				static fn($department) => $department->getId(),
			);

			if (count($departmentsId) === 0)
			{
				$rootDepartment = (new PermissionInvitation($userId))->findFirstPossibleAvailableDepartment();
				if ($rootDepartment === null)
				{
					throw new McpException(message: 'Failed to find available department for the current user. Invite link cannot be generated.', previous: null);
				}

				$departmentsId = [$rootDepartment->getId()];
			}

			$useLocalEmailProgram = $this->isLocalEmailProgram();

			$description =
				$useLocalEmailProgram
				?
					'Invite link generated with local email program.  Use shortLink as the primary link for sharing. '
					. 'The analytics parameters will help to track that the invitation was sent using local email program.'
				:
					'Invite link generated without local email program.  Use shortLink as the primary link for sharing. '
					. 'The analytics parameters will help to track that a local email program is not currently available on this portal'
			;

			$analyticsParams = [
				'st' => [
					'tool' => 'Invitation',
					'category' => 'invitation_by_link',
					'event' => 'openLink',
					'type' => $useLocalEmailProgram ? 'by_local_email_program' : 'by_link',
					'description' => $description,
				],
			];

			$linkGenerator = InviteLinkGenerator::createByDepartmentsIds($departmentsId, [], $analyticsParams);
			if ($linkGenerator === null)
			{
				throw new McpException(message: 'Failed to create invite link generator. No available departments found.', previous: null);
			}

			return [
				'shortLink' => $linkGenerator->getShortLink(),
				'link' => $linkGenerator->getLink(),
				'departmentsId' => $departmentsId,
				'description' => '',
			];
		}
		catch (McpException $e)
		{
			throw $e;
		}
		catch (\Throwable $e)
		{
			throw new McpException($e->getMessage());
		}
	}
}
