<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Catalog;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Dto\NodeSettings;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Activity\Enum\ActivityType;

class NodeCatalogItemDtoFactory
{
	private const DEFAULT_ICON_PATH = '/bitrix/images/bizproc/act_icon.gif';

	public function createByDescription(ActivityDescription $description): NodeCatalogItemDto
	{
		$this->fillTriggerNodeTypeIfNeeded($description);
		$defaultSettings = $description->getNodeSettings() ?? self::makeDefaultSettingsByType(
			$description->getNodeType()
		);

		return new NodeCatalogItemDto(
			id: $description->getClass(),
			type: $description->getNodeType() ?? 'simple',
			presetId: $description->getPresetId(),
			title: $description->getName(),
			subtitle: $description->getDescription(),
			icon: $description->getIcon(),
			iconPath: self::getIconPath($description),
			colorIndex: $description->getColorIndex(),
			properties: $description->get('PROPERTIES'),
			returnProperties: self::makeReturnProperties($description->getClass()),
			defaultSettings: $defaultSettings->toArray(),
		);
	}

	private static function getIconPath(ActivityDescription $description): ?string
	{
		if ($description->getNodeType() === ActivityNodeType::TRIGGER)
		{
			return null;
		}

		$pathToActivity = $description->getPathToActivity();
		$actPath = mb_substr($pathToActivity, mb_strlen($_SERVER['DOCUMENT_ROOT']));
		if (file_exists($pathToActivity . '/icon.gif'))
		{
			return $actPath . '/icon.gif';
		}

		return self::DEFAULT_ICON_PATH;
	}

	private static function makeReturnProperties(array|string $activityOrCode): array
	{
		$props = \CBPRuntime::getRuntime()->getActivityReturnProperties($activityOrCode);
		foreach ($props as $id => &$prop)
		{
			$prop['Id'] = $id;
		}

		return array_values($props);
	}

	private static function makeDefaultSettingsByType(?string $type): NodeSettings
	{
		$defaultSettings = match ($type)
		{
			ActivityNodeType::TRIGGER->value => [
				'width' => 180,
				'height' => 56,
				'ports' => [
					'input' => [],
					'output' => [
						[
							'id' => 'o0',
						],
					],
				],
			],
			ActivityNodeType::COMPLEX->value => [
				'width' => 230,
				'height' => 46,
				'ports' => [
					'input' => [
						[
							'id' => 'i0',
							'title' => 'G1',
						],
					],
					'output' => [],
				],
			],
			ActivityNodeType::TOOL->value => [
				'height' => 46,
				'width' => 230,
				'ports' => [
					'input' => [],
					'output' => [],
					'topAux' => [['id' => 't0']],
				],
			],
			default => [
				'width' => 230,
				'height' => 46,
				'ports' => [
					'input' => [
						[
							'id' => 'i0',
						],
					],
					'output' => [
						[
							'id' => 'o0',
						],
					],
				],
			],
		};

		return NodeSettings::fromArray($defaultSettings);
	}

	private function getNodeTypeByActivityType(array $types): ?string
	{
		return in_array(ActivityType::TRIGGER->value, $types, true) ? ActivityNodeType::TRIGGER->value : null;
	}

	private function fillTriggerNodeTypeIfNeeded(ActivityDescription $description): void
	{
		if ($description->getNodeType() !== null)
		{
			return;
		}

		$nodeType = $this->getNodeTypeByActivityType($description->getType());
		if ($nodeType)
		{
			$description->setNodeType($nodeType);
		}
	}
}
