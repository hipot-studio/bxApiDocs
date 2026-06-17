<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

final class ActivityNodeBuilder
{
	private int $counter = 0;

	public function __construct(
		private readonly ActivityRegistry $registry,
	) {}

	public function reset(): void
	{
		$this->counter = 0;
	}

	public function build(
		string $activityType,
		array $properties,
		Position $position,
		?string $id = null,
	): array
	{
		$name = $id ?? $this->generateNextId();
		$nodeType = $this->registry->getNodeType($activityType);
		$dimensions = $this->registry->getDefaultDimensions($activityType);
		$ports = $this->registry->getDefaultPorts($activityType);
		$icon = $this->registry->getIcon($activityType);
		$colorIndex = $this->registry->getColorIndex($activityType);

		return [
			'Name' => $name,
			'Type' => $activityType,
			'Activated' => 'Y',
			'Properties' => empty($properties) ? new \stdClass() : $properties,
			'Document' => null,
			'Node' => [
				'id' => $name,
				'type' => $nodeType->value,
				'position' => $position->toArray(),
				'dimensions' => $dimensions,
				'ports' => $ports,
				'node' => [
					'type' => $nodeType->value,
					'title' => $properties['Title'] ?? '',
					'colorIndex' => $colorIndex,
					'frameColorName' => null,
					'frameTextAlign' => null,
					'frameSeparatorPosition' => null,
					'icon' => $icon,
				],
			],
		];
	}

	/**
	 * Generates deterministic sequential IDs in A0000_0000_0000_NNNN format.
	 * Manual _id values from config use A{flowId}_* pattern and never collide.
	 */
	private function generateNextId(): string
	{
		$this->counter++;

		if ($this->counter > 9999)
		{
			throw new \OverflowException("Too many auto-generated activity IDs (max 9999). Use explicit _id in template.source.json.");
		}

		return sprintf('A%04d_%04d_%04d_%04d', 0, 0, 0, $this->counter);
	}
}
