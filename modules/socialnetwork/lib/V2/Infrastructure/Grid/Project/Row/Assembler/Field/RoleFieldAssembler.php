<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Project\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Js\ProjectListControllerActionBuilder;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Localization\ActionMessage;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Localization\RoleMessage;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Role;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupUserRelation;

class RoleFieldAssembler extends FieldAssembler
{
	private static function getCssClass(Role $role): string
	{
		return match ($role) {
			Role::Owner => '--role-green',
			Role::Moderator => '--role-yellow',
			Role::Member => '--role-blue',
			default => '',
		};
	}

	public function __construct(
		array $columnIds,
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
		$relation = $data['ROLE_RELATION'] ?? null;
		$projectId = (int)($data['ID'] ?? 0);
		$type = is_string($data['TYPE'] ?? null) ? $data['TYPE'] : Type::Project->value;
		$showRoleActions = (bool)($data['SHOW_ROLE_ACTIONS'] ?? true);

		$html = $relation instanceof WorkgroupUserRelation
			? $this->renderRelation($relation, $projectId, $type)
			: (
				$showRoleActions
					? $this->renderFallbackAction($data, $projectId, $type)
					: ''
			);

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $html;
		}

		return $row;
	}

	private function renderRelation(
		WorkgroupUserRelation $relation,
		int $projectId,
		?string $type,
	): string
	{
		if ($relation->isMember())
		{
			return $this->renderRole($relation);
		}

		if ($relation->isIncomingInvite())
		{
			return $this->renderIncomingInvite($projectId, $type);
		}

		if ($relation->isOutgoingRequest())
		{
			return $this->renderOutgoingRequest($projectId, $type);
		}

		if ($relation->isBan())
		{
			return $this->renderPlainLabel(RoleMessage::get(RoleMessage::BAN));
		}

		return '';
	}

	private function renderFallbackAction(array $data, int $projectId, ?string $type): string
	{
		if ((bool)($data['CAN_DELETE_INCOMING_REQUEST'] ?? false))
		{
			return $this->renderOutgoingRequest($projectId, $type);
		}

		return (bool)($data['CAN_JOIN'] ?? false)
			? $this->renderJoinButton($projectId, $type)
			: '';
	}

	private function renderRole(WorkgroupUserRelation $relation): string
	{
		$cssModifier = self::getCssClass($relation->role);
		$text = RoleMessage::getProjectRole($relation->role, $relation->autoMember);

		if ($text === '' || $cssModifier === '')
		{
			return '';
		}

		return '<span class="sonet-ui-grid-request-cont">'
			. '<span class="ui-label sonet-ui-grid-role ' . $cssModifier . '">'
			. '<span class="ui-label-inner">' . htmlspecialcharsbx($text) . '</span>'
			. '</span>'
			. '</span>';
	}

	private function renderPlainLabel(string $text): string
	{
		if ($text === '')
		{
			return '';
		}

		return '<span class="sonet-ui-grid-request-cont">'
			. '<span class="ui-label">'
			. '<span class="ui-label-inner">' . htmlspecialcharsbx($text) . '</span>'
			. '</span>'
			. '</span>';
	}

	private function renderJoinButton(int $projectId, ?string $type): string
	{
		if ($projectId <= 0)
		{
			return '';
		}

		$text = ActionMessage::get(ActionMessage::JOIN);
		if ($text === '')
		{
			return '';
		}

		$onclick = htmlspecialcharsbx(
			ProjectListControllerActionBuilder::buildJoinAction(
				entityId: $projectId,
				entityType: $type,
				stopPropagation: true,
			),
		);

		return '<span class="sonet-ui-grid-request-cont">'
			. '<span class="ui-label sonet-ui-grid-join" onclick="' . $onclick . '">'
			. '<span class="ui-label-inner">' . htmlspecialcharsbx($text) . '</span>'
			. '</span>'
			. '</span>';
	}

	private function renderIncomingInvite(int $projectId, ?string $type): string
	{
		if ($projectId <= 0)
		{
			return '';
		}

		$text = RoleMessage::get(RoleMessage::REQUEST_G);
		if ($text === '')
		{
			return '';
		}

		$joinTitle = ActionMessage::get(ActionMessage::JOIN);
		$joinOnclick = htmlspecialcharsbx(ProjectListControllerActionBuilder::buildJoinAction(
			entityId: $projectId,
			entityType: $type,
			stopPropagation: true,
		));
		$joinTitleAttr = $joinTitle !== ''
			? ' title="' . htmlspecialcharsbx($joinTitle) . '"'
			: '';

		$cancelTitle = ActionMessage::get(ActionMessage::DELETE_OUTGOING_REQUEST);
		$cancelOnclick = htmlspecialcharsbx(ProjectListControllerActionBuilder::buildRowAction(
			action: 'deleteOutgoingRequest',
			entityId: $projectId,
			entityType: $type,
			stopPropagation: true,
		));
		$cancelTitleAttr = $cancelTitle !== ''
			? ' title="' . htmlspecialcharsbx($cancelTitle) . '"'
			: '';

		return '<span class="sonet-ui-grid-badge-invite-box">'
			. '<span class="ui-label sonet-ui-grid-badge-invite-accept">'
			. '<span class="ui-label-inner">' . htmlspecialcharsbx($text) . '</span>'
			. '</span>'
			. '<span class="ui-label sonet-ui-grid-badge-accept" onclick="' . $joinOnclick . '"' . $joinTitleAttr . '>'
			. '<span class="ui-label-inner"></span>'
			. '</span>'
			. '<span class="ui-label sonet-ui-grid-badge-cancel" onclick="' . $cancelOnclick . '"' . $cancelTitleAttr . '>'
			. '<span class="ui-label-inner"></span>'
			. '</span>'
			. '</span>';
	}

	private function renderOutgoingRequest(int $projectId, ?string $type): string
	{
		if ($projectId <= 0)
		{
			return '';
		}

		$text = RoleMessage::get(RoleMessage::REQUEST_U);
		if ($text === '')
		{
			return '';
		}

		$title = ActionMessage::get(ActionMessage::DELETE_INCOMING_REQUEST);
		$onclick = htmlspecialcharsbx(ProjectListControllerActionBuilder::buildRowAction(
			action: 'deleteIncomingRequest',
			entityId: $projectId,
			entityType: $type,
			stopPropagation: true,
		));
		$titleAttr = $title !== ''
			? ' title="' . htmlspecialcharsbx($title) . '"'
			: '';

		return '<span class="sonet-ui-grid-badge-invite-box">'
			. '<span class="ui-label sonet-ui-grid-badge-invite">'
			. '<span class="ui-label-inner">' . htmlspecialcharsbx($text) . '</span>'
			. '</span>'
			. '<span class="ui-label sonet-ui-grid-badge-cancel" onclick="' . $onclick . '"' . $titleAttr . '>'
			. '<span class="ui-label-inner"></span>'
			. '</span>'
			. '</span>';
	}
}
