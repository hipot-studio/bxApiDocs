<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Catalog;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\ContentBlockResolver;
use Bitrix\Bizproc\Activity\Dto\NodeSettings;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Activity\ReturnPropertiesResolver;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\BizprocDesigner\Internal\Config\Feature;

class NodeCatalogItemDtoFactory
{
	private const DEFAULT_ICON_PATH = '/bitrix/images/bizproc/act_icon.gif';

	public function createByDescription(ActivityDescription $description): NodeCatalogItemDto
	{
		$this->fillTriggerNodeTypeIfNeeded($description);
		$defaultSettings = $description->getNodeSettings() ?? self::makeDefaultSettingsByType(
			$description->getNodeType()
		);

		$defaultProperties = $description->get('PROPERTIES');
		$contentBlock = ContentBlockResolver::resolve(
			(string)$description->getClass(),
			is_array($defaultProperties) ? $defaultProperties : [],
		);

		return new NodeCatalogItemDto(
			id: $description->getClass(),
			type: $description->getNodeType() ?? 'simple',
			// Same gated source as TemplateToNodes reads for a saved block, so a node dropped from the palette
			// opens the very panel it will open again after the template is reloaded.
			servedByUnifiedPanel: self::resolveServedByUnifiedPanel($description),
			presetId: $description->getPresetId(),
			title: $description->getName(),
			subtitle: $description->getDescription(),
			icon: $description->getIcon(),
			iconPath: self::getIconPath($description),
			colorIndex: $description->getColorIndex(),
			contentBlockColor: $description->getContentBlockColor(),
			properties: $description->get('PROPERTIES'),
			returnProperties: ReturnPropertiesResolver::resolve((string)$description->getClass()),
			defaultSettings: $defaultSettings->toArray(),
			hasAuxPorts: $description->getNodeSettings()?->ports?->aux !== null,
			relationsAvailable: self::resolveRelationsAvailable($description),
			contentBlock: $contentBlock?->toArray(),
			contentBlockProducer: ContentBlockResolver::getScopeContribution((string)$description->getClass()),
			contentBlockConsumer: ContentBlockResolver::getScopeConsumption((string)$description->getClass()),
		);
	}

	/**
	 * The panel the node is served by: the descriptor of the activity intersected with the rollout gate of the
	 * complexNodeConnections feature - the same verdict the settings endpoints answer by and TemplateToNodes
	 * publishes with a saved block, so a rolled-back feature returns a translated node to its legacy form
	 * wherever the editor asks about it.
	 */
	private static function resolveServedByUnifiedPanel(ActivityDescription $description): bool
	{
		return Container::instance()->getUnifiedPanelDescriptorProvider()->isSurfaceAvailableForNode(
			(string)$description->getClass(),
			$description,
			Feature::instance()->areComplexNodeConnectionsAvailable(),
		);
	}

	/**
	 * Relations-block availability for a node: its explicit availableBlocks descriptor intersected
	 * with the complexNodeConnections runtime gate — the same gated source TemplateToNodes and
	 * loadSettings use, so the canvas gate is consistent for a node created from the catalog
	 * (drag & drop), not only for one loaded from a saved template. A node without such a
	 * descriptor has no relations, and the resolver answers so itself.
	 */
	private static function resolveRelationsAvailable(ActivityDescription $description): bool
	{
		return Container::instance()->getCapabilityCatalogService()->getRelationsAvailabilityForNode(
			$description,
			Feature::instance()->areComplexNodeConnectionsAvailable(),
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

	/**
	 * Fallback node topology (width/height/ports) for activities that do not declare explicit
	 * NodeSettings. Shared source of truth for the node editor catalog and the agent REST catalog
	 * so both build identical default ports for the same NODE_TYPE.
	 *
	 * OPERATORS is intentionally not a dedicated branch: every in-scope OPERATORS activity ships
	 * explicit NodeSettings, so it never reaches this fallback; a hypothetical OPERATORS activity
	 * without them safely gets the default single input/output topology (i0/o0). COMPLEX keeps its
	 * empty-output branch unchanged (out of scope).
	 */
	public static function makeDefaultSettingsByType(?string $type): NodeSettings
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
				'width' => 260,
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
