<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Web\Uri;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Js\ProjectListControllerActionBuilder;
use Bitrix\Socialnetwork\V2\Internal\Entity\User;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Role;
use Bitrix\Socialnetwork\V2\Internal\Entity\UserCollection;

class MembersFieldAssembler extends FieldAssembler
{
	private const VISIBLE_HEADS = 3;
	private const VISIBLE_MEMBERS = 3;

	public function __construct(
		array $columnIds,
		private readonly string $gridId = '',
		private readonly string $entityType = 'project',
	)
	{
		parent::__construct($columnIds);
	}

	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];

		$data = $row['data'];
		$members = $data['MEMBERS'] ?? null;
		$numberOfMembers = (int)($data['NUMBER_OF_MEMBERS'] ?? 0);
		$numberOfModerators = (int)($data['NUMBER_OF_MODERATORS'] ?? 0);
		$entityId = (int)($data['ID'] ?? 0);
		$entityType = is_string($data['TYPE'] ?? null) ? $data['TYPE'] : $this->entityType;

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $this->renderMembers(
				$members,
				$numberOfMembers,
				$numberOfModerators,
				$entityId,
				$entityType,
			);
		}

		return $row;
	}

	private function renderMembers(
		?UserCollection $members,
		int $numberOfMembers,
		int $numberOfModerators,
		int $entityId,
		?string $entityType,
	): string
	{
		if ($members === null || $members->count() === 0)
		{
			return '';
		}

		$heads = [];
		$regular = [];

		foreach ($members as $user)
		{
			if ($user->role === Role::Owner || $user->role === Role::Moderator)
			{
				$heads[] = $user;
			}
			else
			{
				$regular[] = $user;
			}
		}

		$heads = $this->moveOwnerToFront($heads);
		$visibleHeads = array_slice($heads, 0, self::VISIBLE_HEADS);
		$headsLayout = empty($visibleHeads)
			? ''
			: $this->renderGroup(
				$visibleHeads,
				max(0, $numberOfModerators - self::VISIBLE_HEADS),
				true,
			)
		;

		$visibleMembers = array_slice($regular, 0, self::VISIBLE_MEMBERS);
		$membersLayout = empty($visibleMembers)
			? ''
			: $this->renderGroup(
				$visibleMembers,
				max(0, $numberOfMembers - $numberOfModerators - self::VISIBLE_MEMBERS),
				false,
			)
		;

		$onclick = $this->getPopupOnclick($entityId, $entityType);

		return '<div class="sonet-ui-grid-user-list-container" onclick="' . htmlspecialcharsbx($onclick) . '">'
			. $headsLayout
			. $membersLayout
			. '</div>';
	}

	/**
	 * @param User[] $heads
	 * @return User[]
	 */
	private function moveOwnerToFront(array $heads): array
	{
		$sortedHeads = [];

		foreach ($heads as $user)
		{
			if ($user->role === Role::Owner)
			{
				array_unshift($sortedHeads, $user);

				continue;
			}

			$sortedHeads[] = $user;
		}

		return $sortedHeads;
	}

	private function renderGroup(array $users, int $remaining, bool $isHeads): string
	{
		if (empty($users) && $remaining <= 0)
		{
			return '';
		}

		$avatarsHtml = '';
		foreach ($users as $user)
		{
			$avatarsHtml .= $this->renderAvatar($user);
		}

		$counterHtml = $this->renderCounter($remaining);

		$modifier = $isHeads ? ' sonet-ui-grid-user-list--green' : '';

		return '<div style="display: inline-block">'
			. '<div class="sonet-ui-grid-user-list' . $modifier . '">'
			. $avatarsHtml
			. $counterHtml
			. '</div>'
			. '</div>';
	}

	private function renderAvatar(User $user): string
	{
		$style = '';
		$photoSrc = $user->image?->src ?? null;
		if ($photoSrc !== null && $photoSrc !== '')
		{
			$style = ' style="background-image: url(\'' . Uri::urnEncode($photoSrc) . '\')"';
		}

		return '<a class="sonet-ui-grid-user-item"' . $style . '>'
			. '<div class="sonet-ui-grid-user-crown"></div>'
			. '</a>';
	}

	private function renderCounter(int $remaining): string
	{
		if ($remaining <= 0)
		{
			return '';
		}

		return '<div class="sonet-ui-grid-user-count">'
			. '<span class="sonet-ui-grid-user-plus">+</span>'
			. $remaining
			. '</div>';
	}

	private function getPopupOnclick(int $entityId, ?string $entityType): string
	{
		if ($entityId <= 0)
		{
			return '';
		}

		$type = (
			is_string($entityType) && Type::isValid($entityType)
				? $entityType
				: $this->entityType
		);

		return ProjectListControllerActionBuilder::buildMembersPopupAction($entityId, $type);
	}
}
