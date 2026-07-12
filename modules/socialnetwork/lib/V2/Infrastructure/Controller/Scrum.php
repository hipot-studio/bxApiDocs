<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Socialnetwork\V2\Internal\Access\Scrum\Permission;
use Bitrix\Socialnetwork\V2\Public\Command\Project\ArchiveProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\DeleteIncomingRequestCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\DeleteOutgoingRequestCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\DeleteProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\JoinProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\SwitchFavoriteCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\SwitchPinCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Scrum\DeleteScrumCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Scrum\UpdateScrumTagsCommand;
use Bitrix\Socialnetwork\V2\Public\Dto;
use Bitrix\Socialnetwork\V2\Public\Grid\PinMode;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectMemberProvider;

class Scrum extends BaseController
{
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				Dto\Scrum\Scrum::class,
				'scrum',
				fn(string $className, int $scrumId): ?Dto\Scrum\Scrum
					=> $this->getWithAccess($this, 'scrum', new Dto\Scrum\Scrum(id: $scrumId)),
			),
		];
	}

	/**
	 * @ajaxAction socialnetwork.V2.Scrum.setArchive
	 */
	public function setArchiveAction(
		#[Permission\Update]
		Dto\Scrum\Scrum $scrum,
		bool $archive,
	): ?array
	{
		$result = (new ArchiveProjectCommand(
			projectId: $scrum->id,
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
	 * @ajaxAction socialnetwork.V2.Scrum.join
	 */
	public function joinAction(
		#[Permission\Join]
		Dto\Scrum\Scrum $scrum,
	): ?array
	{
		$result = (new JoinProjectCommand(
			projectId: $scrum->id,
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
	 * @ajaxAction socialnetwork.V2.Scrum.deleteIncomingRequest
	 */
	public function deleteIncomingRequestAction(
		#[Permission\DeleteIncomingRequest]
		Dto\Scrum\Scrum $scrum,
	): ?array
	{
		$result = (new DeleteIncomingRequestCommand(
			groupId: $scrum->id,
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
	 * @ajaxAction socialnetwork.v2.Scrum.deleteOutgoingRequest
	 */
	public function deleteOutgoingRequestAction(
		#[Permission\DeleteOutgoingRequest]
		Dto\Scrum\Scrum $scrum,
	): ?array
	{
		$result = (new DeleteOutgoingRequestCommand(
			groupId: $scrum->id,
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
	 * @ajaxAction socialnetwork.V2.Scrum.updateTags
	 */
	public function updateTagsAction(
		#[Permission\Update]
		Dto\Scrum\Scrum $scrum,
		array $tags = [],
	): ?array
	{
		$result = (new UpdateScrumTagsCommand(
			scrumId: $scrum->id,
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
	 * @ajaxAction socialnetwork.V2.Scrum.changePin
	 */
	public function changePinAction(
		#[Permission\Read]
		Dto\Scrum\Scrum $scrum,
		string $mode = '',
	): ?array
	{
		$result = (new SwitchPinCommand(
			groupId: $scrum->id,
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
	 * @ajaxAction socialnetwork.V2.Scrum.changeFavorite
	 */
	public function changeFavoriteAction(
		#[Permission\Read]
		Dto\Scrum\Scrum $scrum,
	): ?array
	{
		$result = (new SwitchFavoriteCommand(
			groupId: $scrum->id,
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
	 * @ajaxAction socialnetwork.V2.Scrum.getMembers
	 */
	public function getMembersAction(
		#[Permission\Read]
		Dto\Scrum\Scrum $scrum,
		ProjectMemberProvider $memberProvider,
		string $type = Dto\Project\MemberFilterType::All->value,
		int $page = 1,
	): ?array
	{
		return $memberProvider->getPagedMembers(
			projectId: $scrum->id,
			type: Dto\Project\MemberFilterType::tryFrom($type) ?? Dto\Project\MemberFilterType::All,
			page: max(1, $page),
		);
	}

	/**
	 * @ajaxAction socialnetwork.V2.Scrum.delete
	 */
	public function deleteAction(
		#[Permission\Delete]
		Dto\Scrum\Scrum $scrum,
	): ?array
	{
		$result = (new DeleteScrumCommand(
			scrumId: $scrum->id,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['success' => true];
	}
}
