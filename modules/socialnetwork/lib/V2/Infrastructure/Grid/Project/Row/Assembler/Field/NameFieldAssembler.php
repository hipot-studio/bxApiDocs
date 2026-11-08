<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Project\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Js\ProjectListControllerActionBuilder;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Avatar;

class NameFieldAssembler extends FieldAssembler
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
		$hasCollabers = (bool)($data['HAS_COLLABERS'] ?? false);
		$viewUrl = (string)($data['VIEW_URL'] ?? '');

		$escapedName = htmlspecialcharsbx($name);
		$picPath = htmlspecialcharsbx($avatar?->url ?? '');
		$color = htmlspecialcharsbx((string)($data['COLOR'] ?? ''));
		$entityId = (int)($data['ID'] ?? 0);
		$onclickAttr = $this->buildOnclickAttribute($entityId, $type);
		$nameNode = $viewUrl === ''
			? '<span>' . $escapedName . '</span>'
			: '<a href="' . htmlspecialcharsbx($viewUrl) . '" class="socialnetwork-project-list-name-link"' . $onclickAttr . '>'
				. $escapedName
				. '</a>';

		$html = '<div class="socialnetwork-project-list-name-container">'
			. $this->renderAvatar(
				type: $type,
				hasCollabers: $hasCollabers,
				escapedName: $escapedName,
				picPath: $picPath,
				color: $color,
			)
			. $nameNode
			. '</div>';

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $html;
		}

		return $row;
	}

	private function buildOnclickAttribute(int $entityId, ?string $entityType): string
	{
		$call = ProjectListControllerActionBuilder::buildNotifyOpenProjectAction($entityId, $entityType);

		return $call === '' ? '' : ' onclick="' . htmlspecialcharsbx($call) . '"';
	}

	private function renderAvatar(
		?string $type,
		bool $hasCollabers,
		string $escapedName,
		string $picPath,
		string $color,
	): string
	{
		if ($type === Type::Collab->value)
		{
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

		return '<span class="socialnetwork-project-list-scrum-avatar"'
			. ' data-title="' . $escapedName . '"'
			. ' data-pic="' . $picPath . '"'
			. '></span>';
	}
}
