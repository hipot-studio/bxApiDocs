<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field;

use Bitrix\Main\Grid\CellActions;
use Bitrix\Main\Grid\CellActionState;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Js\ProjectListControllerActionBuilder;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Localization\ActionMessage;

class PinFieldAssembler extends FieldAssembler
{
	public function __construct(
		array $columnIds,
		private readonly string $entityType = Type::Project->value,
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

		$groupId = (int)($row['data']['ID'] ?? 0);
		$isPinned = (bool)($row['data']['IS_PINNED'] ?? false);
		$entityType = is_string($row['data']['TYPE'] ?? null) ? $row['data']['TYPE'] : $this->entityType;
		$pinLabel = ActionMessage::get(ActionMessage::PIN);
		$unpinLabel = ActionMessage::get(ActionMessage::UNPIN);

		if ($groupId <= 0)
		{
			return $row;
		}

		$pin = [
			'class' => [
				CellActions::PIN,
				$isPinned ? CellActionState::ACTIVE : CellActionState::SHOW_BY_HOVER,
			],
			'attributes' => [
				'role' => 'button',
				'tabindex' => '0',
				'aria-pressed' => $isPinned ? 'true' : 'false',
				'aria-label' => $isPinned ? $unpinLabel : $pinLabel,
				'title' => $isPinned ? $unpinLabel : $pinLabel,
				'data-testid' => 'socialnetwork-project-list-pin-' . $groupId,
				'data-pin-label' => $pinLabel,
				'data-unpin-label' => $unpinLabel,
			],
			'events' => [
				'click' => ProjectListControllerActionBuilder::buildBindAction(
					method: 'changePin',
					entityId: $groupId,
					entityType: $entityType,
				),
				'keydown' => ProjectListControllerActionBuilder::buildBindAction(
					method: 'onPinKeyDown',
					entityId: $groupId,
					entityType: $entityType,
				),
			],
		];

		$row['cellActions'] ??= [];

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['cellActions'][$columnId] = [$pin];
		}

		return $row;
	}
}
