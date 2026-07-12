<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Socialnetwork\V2\Internal\Access\LegacyGroup\Permission;
use Bitrix\Socialnetwork\V2\Public\Command\Project\ArchiveProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\DeleteIncomingRequestCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\DeleteOutgoingRequestCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\JoinProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\SwitchFavoriteCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\SwitchPinCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\UpdateProjectTagsCommand;
use Bitrix\Socialnetwork\V2\Public\Dto;
use Bitrix\Socialnetwork\V2\Public\Grid\PinMode;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectMemberProvider;

/**
 * Transitional grid controller for legacy group types (TYPE=project, TYPE=group).
 * Used by common/user grid mode where mixed group types are displayed.
 *
 * Uses LegacyGroup\Permission\* which delegates to LegacyGroupAccessService
 * (GroupAccessController for non-collabs, CollabAccessController for collabs).
 *
 * After all groups are converted to collabs, delete this controller
 * and switch grid to socialnetwork.v2.Project actionPrefix.
 */
class LegacyGroup extends BaseController
{
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				Dto\LegacyGroup\LegacyGroup::class,
				'legacyGroup',
				fn(string $className, int $legacyGroupId): ?Dto\LegacyGroup\LegacyGroup
					=> $this->getWithAccess($this, 'legacyGroup', new Dto\LegacyGroup\LegacyGroup(id: $legacyGroupId)),
			),
		];
	}

	/**
	 * @ajaxAction socialnetwork.V2.LegacyGroup.setArchive
	 */
	public function setArchiveAction(
		#[Permission\Update]
		Dto\LegacyGroup\LegacyGroup $legacyGroup,
		bool $archive,
	): ?array
	{
		$result = (new ArchiveProjectCommand(
			projectId: $legacyGroup->id,
			archive: $archive,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['success' => true];
	}

	/**
	 * @ajaxAction socialnetwork.V2.LegacyGroup.join
	 */
	public function joinAction(
		#[Permission\Join]
		Dto\LegacyGroup\LegacyGroup $legacyGroup,
	): ?array
	{
		$result = (new JoinProjectCommand(
			projectId: $legacyGroup->id,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['success' => true];
	}

	/**
	 * @ajaxAction socialnetwork.V2.LegacyGroup.deleteIncomingRequest
	 */
	public function deleteIncomingRequestAction(
		#[Permission\DeleteIncomingRequest]
		Dto\LegacyGroup\LegacyGroup $legacyGroup,
	): ?array
	{
		$result = (new DeleteIncomingRequestCommand(
			groupId: $legacyGroup->id,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['success' => true];
	}

	/**
	 * @ajaxAction socialnetwork.V2.LegacyGroup.deleteOutgoingRequest
	 */
	public function deleteOutgoingRequestAction(
		#[Permission\DeleteOutgoingRequest]
		Dto\LegacyGroup\LegacyGroup $legacyGroup,
	): ?array
	{
		$result = (new DeleteOutgoingRequestCommand(
			groupId: $legacyGroup->id,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['success' => true];
	}

	/**
	 * @ajaxAction socialnetwork.V2.LegacyGroup.updateTags
	 */
	public function updateTagsAction(
		#[Permission\Update]
		Dto\LegacyGroup\LegacyGroup $legacyGroup,
		array $tags = [],
	): ?array
	{
		$result = (new UpdateProjectTagsCommand(
			projectId: $legacyGroup->id,
			tags: $tags,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['success' => true];
	}

	/**
	 * @ajaxAction socialnetwork.V2.LegacyGroup.changeFavorite
	 */
	public function changeFavoriteAction(
		#[Permission\Read]
		Dto\LegacyGroup\LegacyGroup $legacyGroup,
	): ?array
	{
		$result = (new SwitchFavoriteCommand(
			groupId: $legacyGroup->id,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @ajaxAction socialnetwork.V2.LegacyGroup.changePin
	 */
	public function changePinAction(
		#[Permission\Read]
		Dto\LegacyGroup\LegacyGroup $legacyGroup,
		string $mode = '',
	): ?array
	{
		$result = (new SwitchPinCommand(
			groupId: $legacyGroup->id,
			userId: $this->userId,
			mode: PinMode::fromMode($mode),
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @ajaxAction socialnetwork.V2.LegacyGroup.getMembers
	 */
	public function getMembersAction(
		#[Permission\Read]
		Dto\LegacyGroup\LegacyGroup $legacyGroup,
		ProjectMemberProvider $memberProvider,
		string $type = Dto\Project\MemberFilterType::All->value,
		int $page = 1,
	): ?array
	{
		return $memberProvider->getPagedMembers(
			projectId: $legacyGroup->id,
			type: Dto\Project\MemberFilterType::tryFrom($type) ?? Dto\Project\MemberFilterType::All,
			page: max(1, $page),
		);
	}
}
