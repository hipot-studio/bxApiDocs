<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Bizproc\Activity\Dto\NodeSettings;
use Bitrix\Bizproc\Activity\Enum\ActivityPortRole;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingConstraintCollection;

class BlockTypeDetail
{
	public function __construct(
		public readonly BlockType $block,
		public readonly SettingCollection $settings,
		public readonly ReturnFieldCollection $returnFields,
		/** @var array<string, string> [type => description, ...] $describedTypes */
		public readonly array $describedTypes = [],
		/** @var list<string> activity properties that exist but cannot be expressed in the settings schema */
		public readonly array $skippedSettings = [],
		/**
		 * @var list<string> names whose value may only be carried over verbatim from the published template.
		 *     Unlike the list above this is a declared contract and is published with the block.
		 */
		public readonly array $carriedSettings = [],
		/** Cross-setting rules of the block, judged after defaults and baseline values are applied. */
		public readonly SettingConstraintCollection $settingConstraints = new SettingConstraintCollection(),
		/** @var array<string, mixed>|null preset default property values (null = no preset applied) */
		public readonly ?array $defaultValues = null,
		/** Node topology (width/height/ports) from the same source as the node editor; null = block has no node settings */
		public readonly ?NodeSettings $nodeSettings = null,
		/** Complex-node detail (sub-action dictionary + fixed document type + filter support); null = block is not a complex node */
		public readonly ?ComplexBlockDetail $complexDetail = null,
	) {}

	public function toArray(): array
	{
		// Unconditional up to defaultValues: an empty collection is a legal value of these keys, and an absent
		// key reads as "unknown" rather than "none". Below the line the keys stay conditional because null
		// means "does not apply to this block" there.
		$array = [
			'block' => $this->block->toArray(),
			'settings' => $this->settings->toArray(),
			'carriedSettings' => $this->carriedSettings,
			'settingConstraints' => $this->settingConstraints->toArray(),
			'returnFields' => $this->returnFields->toArray(),
			'typesDescription' => $this->describedTypes,
		];

		if ($this->defaultValues !== null)
		{
			$array['defaultValues'] = $this->defaultValues;
		}

		if ($this->nodeSettings !== null)
		{
			$array['defaultSettings'] = $this->nodeSettings->toArray();
		}

		if ($this->complexDetail !== null)
		{
			$array['complexActions'] = $this->complexDetail->toArray();
		}

		return $array;
	}

	/**
	 * Port title a loop node used to mark its loop-back port with, before the meaning of a port became a
	 * machine role ({@see ActivityPortRole::LoopBody}). Kept as the compatible fallback for a third-party
	 * block that declares no role, so such a block keeps the loop-closure check it has today.
	 *
	 * @deprecated read {@see \Bitrix\Bizproc\Activity\Dto\Port::$role} instead
	 */
	public const LOOPBACK_PORT_TITLE = '->>';

	/**
	 * @return array{input: list<string>, output: list<string>} known port ids per direction (empty lists when unknown)
	 */
	public function getPortIds(): array
	{
		$ports = $this->nodeSettings?->ports;
		if ($ports === null)
		{
			return ['input' => [], 'output' => []];
		}

		return [
			'input' => self::collectPortIds($ports->input?->toArray() ?? []),
			'output' => self::collectPortIds($ports->output?->toArray() ?? []),
		];
	}

	/**
	 * Ids of the block's loop-back input ports: inputs whose role is LoopBody, that is where the body of the
	 * loop returns. A loop node with a loop-back *input* (ForEach:i1) returns a non-empty list; non-loop
	 * blocks return an empty list.
	 *
	 * Note the intentional asymmetry: this reports only loop-back *inputs*. The same role on an output means
	 * the way into the body, so While - which carries LoopBody on its output o0 alone and closes the body
	 * back into the ordinary entry input i0 - returns [] here, by design. Consequently the input-based
	 * loop-closure check in {@see AgentConnectionsValidator::validateLoopClosure()} applies to ForEach but
	 * not to While. This is a limitation of the input-based guard, not a topology defect.
	 *
	 * @return list<string>
	 */
	public function getLoopbackInputPortIds(): array
	{
		$input = $this->nodeSettings?->ports?->input?->ports ?? [];

		$ids = [];
		foreach ($input as $port)
		{
			if ($port->id !== '' && self::isLoopbackInputPort($port->role, $port->title))
			{
				$ids[] = $port->id;
			}
		}

		return $ids;
	}

	/**
	 * A block that declares a role is judged by it alone. The title is read only for a block that declares
	 * none - a third-party block written before roles existed - and that fallback is the deprecated path.
	 */
	private static function isLoopbackInputPort(?ActivityPortRole $role, string $title): bool
	{
		return $role === null
			? $title === self::LOOPBACK_PORT_TITLE
			: $role === ActivityPortRole::LoopBody
		;
	}

	/**
	 * @param list<array{id?: string}> $ports
	 * @return list<string>
	 */
	private static function collectPortIds(array $ports): array
	{
		$ids = [];
		foreach ($ports as $port)
		{
			$id = (string)($port['id'] ?? '');
			if ($id !== '')
			{
				$ids[] = $id;
			}
		}

		return $ids;
	}
}