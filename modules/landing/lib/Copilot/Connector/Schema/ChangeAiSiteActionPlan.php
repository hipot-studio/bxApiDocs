<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema;

use Bitrix\Landing\Copilot\Connector\Schema\Dto\SchemaNodeDto;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;

class ChangeAiSiteActionPlan extends Schema
{
	protected function getName(): string
	{
		return 'changeAiSiteActionPlanSchema';
	}

	protected function getStructure(): SchemaNodeDto
	{
		return new SchemaNodeDto('object', null, [
			'properties' => [
				new SchemaNodeDto('array', 'actions', [
					'items' => new SchemaNodeDto('object', null, [
						'properties' => [
							new SchemaNodeDto('string', 'actionType', [
								'enum' => [
									'update_block',
									'add_block',
									'delete_block',
									'move_block',
								],
							]),
							new SchemaNodeDto('integer', 'blockId'),
							new SchemaNodeDto('string', 'placementMode', [
								'enum' => [
									'before',
									'after',
									'prepend',
									'append',
								],
							]),
							new SchemaNodeDto('integer', 'placementBlockId'),
							new SchemaNodeDto('string', 'instruction'),
								new SchemaNodeDto('string', 'editMode', [
									'enum' => [
										ChangeAiSiteState::EDIT_MODE_TARGETED,
										ChangeAiSiteState::EDIT_MODE_BALANCED,
										ChangeAiSiteState::EDIT_MODE_GLOBAL,
									],
								]),
						],
					]),
				]),
				new SchemaNodeDto('string', 'globalConstraints'),
				new SchemaNodeDto('string', 'imageStyleBrief'),
				new SchemaNodeDto('string', 'designBrief'),
			],
		]);
	}
}
