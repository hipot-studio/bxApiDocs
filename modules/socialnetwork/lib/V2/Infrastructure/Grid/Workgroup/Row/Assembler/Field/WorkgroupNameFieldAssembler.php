<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Workgroup\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Project\Row\Assembler\Field\AvatarBorderVariant;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Avatar;

/**
 * Name field assembler for ALL workgroup types.
 * Renders hexagon avatar for collabs, round avatar for all other workgroup types.
 */
class WorkgroupNameFieldAssembler extends FieldAssembler
{
	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];

		$data = $row['data'];
		$name = $data['NAME'] ?? '';
		/** @var Avatar|null $avatar */
		$avatar = $data['AVATAR'] ?? null;
		$type = $data['TYPE'] ?? null;
		$viewUrl = (string)($data['VIEW_URL'] ?? '');

		$escapedName = htmlspecialcharsbx($name);
		$picPath = htmlspecialcharsbx($avatar?->url ?? '');

		$avatarSpan = $type === Type::Collab->value
			? $this->renderCollabAvatar(data: $data, escapedName: $escapedName, picPath: $picPath)
			: $this->renderRoundAvatar(escapedName: $escapedName, picPath: $picPath);

		$html = '<div class="socialnetwork-project-list-name-container">'
			. $avatarSpan
			. $this->renderNameNode($escapedName, $viewUrl)
			. '</div>';

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $html;
		}

		return $row;
	}

	private function renderCollabAvatar(array $data, string $escapedName, string $picPath): string
	{
		$hasCollabers = (bool)($data['HAS_COLLABERS'] ?? false);
		$color = htmlspecialcharsbx((string)($data['COLOR'] ?? ''));
		$borderVariant = $hasCollabers
			? AvatarBorderVariant::Guest
			: AvatarBorderVariant::Project;

		return '<span class="socialnetwork-project-list-avatar"'
			. ' data-border-variant="' . $borderVariant->value . '"'
			. ' data-title="' . $escapedName . '"'
			. ' data-pic="' . $picPath . '"'
			. ' data-color="' . $color . '"'
			. '></span>';
	}

	private function renderRoundAvatar(string $escapedName, string $picPath): string
	{
		return '<span class="socialnetwork-project-list-scrum-avatar"'
			. ' data-title="' . $escapedName . '"'
			. ' data-pic="' . $picPath . '"'
			. '></span>';
	}

	private function renderNameNode(string $escapedName, string $viewUrl): string
	{
		if ($viewUrl === '')
		{
			return '<span>' . $escapedName . '</span>';
		}

		return '<a href="' . htmlspecialcharsbx($viewUrl) . '" class="socialnetwork-project-list-name-link">'
			. $escapedName
			. '</a>';
	}
}
