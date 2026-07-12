<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Workgroup\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Js\ProjectListControllerActionBuilder;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Localization\ActionMessage;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Localization\RoleMessage;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Role;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupUserRelation;

/**
 * Role field assembler for ALL workgroup types.
 * Shows role name based on workgroup type (project vs scrum naming).
 */
class WorkgroupRoleFieldAssembler extends FieldAssembler
{
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
		$groupId = (int)($data['ID'] ?? 0);
		$type = $data['TYPE'] ?? null;
		$scrumMasterId = (int)($data['SCRUM_MASTER_ID'] ?? 0);
		$showRoleActions = (bool)($data['SHOW_ROLE_ACTIONS'] ?? true);

		$html = $relation instanceof WorkgroupUserRelation
			? $this->renderRelation($relation, $groupId, $type, $scrumMasterId)
			: (
				$showRoleActions
					? $this->renderFallbackAction($data, $groupId)
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
		int $groupId,
		?string $type,
		int $scrumMasterId,
	): string
	{
		if ($relation->isMember())
		{
			return $this->renderRole($relation, $type, $scrumMasterId);
		}

		if ($relation->isIncomingInvite())
		{
			return $this->renderIncomingInvite($groupId, $type);
		}

		if ($relation->isOutgoingRequest())
		{
			return $this->renderOutgoingRequest($groupId, $type);
		}

		if ($relation->isBan())
		{
			return $this->renderPlainLabel(RoleMessage::get(RoleMessage::BAN));
		}

		return '';
	}

	private function renderFallbackAction(array $data, int $groupId): string
	{
		$type = is_string($data['TYPE'] ?? null) ? $data['TYPE'] : Type::Group->value;

		if ((bool)($data['CAN_DELETE_INCOMING_REQUEST'] ?? false))
		{
			return $this->renderOutgoingRequest($groupId, $type);
		}

		return (bool)($data['CAN_JOIN'] ?? false)
			? $this->renderJoinButton($groupId, $type)
			: '';
	}

	private function renderRole(
		WorkgroupUserRelation $relation,
		?string $type,
		int $scrumMasterId,
	): string
	{
		$cssModifier = self::getCssClass($relation->role);
		if ($cssModifier === '')
		{
			return '';
		}

		$isScrumMaster = $scrumMasterId > 0 && $scrumMasterId === $relation->userId;
		$parts = [];

		if (!($isScrumMaster && $relation->role === Role::Moderator && !$relation->autoMember))
		{
			$text = $this->getRoleText($relation, $type);
			if ($text !== '')
			{
				$parts[] = $text;
			}
		}

		if ($isScrumMaster)
		{
			$scrumMasterLabel = RoleMessage::get(RoleMessage::SCRUM_MASTER);
			if ($scrumMasterLabel !== '')
			{
				$parts[] = $scrumMasterLabel;
			}
		}

		if (empty($parts))
		{
			return '';
		}

		return '<span class="sonet-ui-grid-request-cont">'
			. '<span class="ui-label sonet-ui-grid-role ' . $cssModifier . '">'
			. '<span class="ui-label-inner">' . htmlspecialcharsbx(implode(', ', $parts)) . '</span>'
			. '</span>'
			. '</span>';
	}

	private function renderJoinButton(int $groupId, ?string $type): string
	{
		if ($groupId <= 0)
		{
			return '';
		}

		$text = ActionMessage::get(ActionMessage::JOIN);

		$onclick = htmlspecialcharsbx(ProjectListControllerActionBuilder::buildJoinAction(
			entityId: $groupId,
			entityType: $type,
			stopPropagation: true,
		));

		return '<span class="sonet-ui-grid-request-cont">'
			. '<span class="ui-label sonet-ui-grid-join"'
			. ' onclick="' . $onclick . '"'
			. '>'
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

	private function renderIncomingInvite(int $groupId, ?string $type): string
	{
		if ($groupId <= 0)
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
			entityId: $groupId,
			entityType: $type,
			stopPropagation: true,
		));
		$joinTitleAttr = $joinTitle !== ''
			? ' title="' . htmlspecialcharsbx($joinTitle) . '"'
			: '';

		$cancelTitle = ActionMessage::get(ActionMessage::DELETE_OUTGOING_REQUEST);
		$cancelOnclick = htmlspecialcharsbx(ProjectListControllerActionBuilder::buildRowAction(
			action: 'deleteOutgoingRequest',
			entityId: $groupId,
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

	private function renderOutgoingRequest(int $groupId, ?string $type): string
	{
		if ($groupId <= 0)
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
			entityId: $groupId,
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

	private static function getCssClass(Role $role): string
	{
		return match ($role)
		{
			Role::Owner => '--role-green',
			Role::Moderator => '--role-yellow',
			Role::Member => '--role-blue',
			default => '',
		};
	}

	private function getRoleText(WorkgroupUserRelation $relation, ?string $type): string
	{
		return match ($type)
		{
			Type::Group->value => Loc::getMessage(
				'SONET_V2_GRID_WORKGROUP_ROLE_'
				. $relation->role->value
				. ($relation->autoMember ? '_AUTO' : '')
			) ?? '',
			Type::Scrum->value => RoleMessage::getScrumRole($relation->role, $relation->autoMember),
			default => RoleMessage::getProjectRole($relation->role, $relation->autoMember),
		};
	}
}
