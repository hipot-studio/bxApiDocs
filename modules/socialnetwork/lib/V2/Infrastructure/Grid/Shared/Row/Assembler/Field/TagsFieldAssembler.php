<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Web\Json;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Js\ProjectListControllerActionBuilder;

class TagsFieldAssembler extends FieldAssembler
{
	public function __construct(
		array $columnIds,
		private readonly string $gridId = '',
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

		$row['columns'] ??= [];

		$groupId = (int)($row['data']['ID'] ?? 0);
		$canModify = (bool)($row['data']['CAN_MODIFY'] ?? false);
		$entityType = is_string($row['data']['TYPE'] ?? null) ? $row['data']['TYPE'] : $this->entityType;

		foreach ($this->getColumnIds() as $columnId)
		{
			$tags = $row['data'][$columnId] ?? [];

			$row['columns'][$columnId] = $this->buildTagsData(
				tags: is_array($tags) ? $tags : [],
				groupId: $groupId,
				canModify: $canModify,
				entityType: $entityType,
			);
		}

		return $row;
	}

	private function buildTagsData(array $tags, int $groupId, bool $canModify, ?string $entityType): array
	{
		$result = [
			'items' => [],
		];

		foreach ($tags as $tag)
		{
			$encodedData = Json::encode(['TAG' => $tag]);

			$result['items'][] = [
				'text' => $tag,
				'active' => false,
				'events' => [
					'click' => 'BX.Socialnetwork.Project.List.Controller'
						. '.tagClick.bind(BX.Socialnetwork.Project.List.Controller, '
						. $encodedData . ')',
				],
			];
		}

		if ($canModify && $groupId > 0)
		{
			$result['addButton'] = [
				'events' => [
					'click' => ProjectListControllerActionBuilder::buildBindAction(
						method: 'tagAddClick',
						entityId: $groupId,
						entityType: $entityType,
					),
				],
			];
		}

		return $result;
	}
}
