<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

/**
 * The outputs a block of the agent graph can be addressed by, resolved from the graph as it was submitted
 * (BRIEF § 4.2). The package has two halves, and only the first of them is written in the description of the
 * type: the declared `RETURN`, plus whatever the properties an `ADDITIONAL_RESULT` marker points at hold inside
 * the instance itself - a trigger's `Return`, a node's `FilterReturnPropertiesMap`, a storage read's
 * `OutputFields`. This mirrors {@see \CBPRuntime::getActivityReturnProperties()}, the one method both the node
 * editor and the reverse projection of the contour build their output list with
 * ({@see AiAssistantWorkflowTemplateConverterService}), so a reference the agent was handed by `template.get` is
 * the reference this class accepts back.
 *
 * The second half is why an answer is not always possible. A marker key names a value the WRITE path derives -
 * the properties dialog recomputes it on save, and the reverse projection withholds the derived ones from the
 * agent altogether - so an instance carrying no value under a marker its type declares leaves the package
 * incomplete. That is not an empty package and must never be reported as one: the type has honestly declared
 * that part of its outputs is not in its description, and the caller answers such a reference with a warning
 * rather than a refusal. A type declaring no marker at all is answered exactly, empty package included.
 *
 * An incomplete package still carries its first half, and {@see self::knownOutputsOf()} answers with it. The
 * halves are kept apart because a reference landing in the declared one needs no warning at all: a loop node
 * declares `Key` and `Value` in its own description and expands its additional-result marker from a value the
 * dialog derives, so `{=loop:Value}` was met with "the outputs cannot be listed" while the catalog listed them
 * in the same breath - a warning on every graph that carries a loop, which teaches the agent to ignore the
 * channel. The rule itself is unchanged: an honestly incomputable perimeter is still a warning, now only where
 * the reference actually falls outside what the type declares.
 *
 * One resolution per block and no more (ADR § 5, the cost of the checks): a graph reading one block from a dozen
 * settings walks the catalog once, and two blocks of one type are still two resolutions - their outputs differ by
 * their own properties.
 */
final class BlockOutputResolver
{
	/**
	 * Markers whose value the SERVER derives from another setting of the same block, keyed by the lower-cased type.
	 * Two things follow, and both are the reason this list exists at all.
	 *
	 * The value is declared computed by the schema of the activity (BRIEF § 1.1), so what the agent sent under the
	 * marker decides nothing here - reading it would make the verdict on an expression depend on a value the
	 * contract promises to ignore. A storage create node carrying the StorageFields of its previous columns would
	 * then refuse `{=node:NEW_COLUMN}` for a column its own SelectedFields declares, while the same graph without
	 * that value passes.
	 *
	 * What the marker WILL hold is derivable all the same, from the setting it is built of, so those keys join the
	 * declared half: a reference to a column the block creates needs no warning. The perimeter still counts as
	 * incomplete - the server adds the service columns of every storage on top - so a reference outside it stays a
	 * warning rather than a refusal.
	 *
	 * @var array<string, array{marker: string, from: string, key: string}>
	 */
	private const DERIVED_MARKERS = [
		'createstoragenode' => ['marker' => 'StorageFields', 'from' => 'SelectedFields', 'key' => 'code'],
	];

	/** @var array<string, array{type: string, properties: array}> submitted blocks by id */
	private array $blocksById = [];

	/**
	 * @var array<string, array{outputs: list<string>|null, known: list<string>, failed: bool}> the resolution per
	 *      block id, memoised whatever it turned out to be - a failure included, so one block cannot be resolved
	 *      twice and answered differently
	 */
	private array $resolutions = [];

	/** @var callable(string): ?array reads the description of an activity type; null means the type is unknown */
	private $descriptionProvider;

	/**
	 * @param mixed $blocks blocks as the entry submitted them: the outputs of a block are a function of its own
	 *        properties, and the block that is REFERENCED may well be one this pass has not judged yet
	 * @param null|callable(string): ?array $descriptionProvider the catalog read, injectable so the failure of the
	 *        provider is a state a test can produce; the default reads the runtime
	 */
	public function __construct(mixed $blocks, ?callable $descriptionProvider = null)
	{
		$this->descriptionProvider = $descriptionProvider ?? static fn(string $blockType): ?array
			=> \CBPRuntime::getRuntime()->getActivityDescription($blockType)
		;

		if (!is_array($blocks))
		{
			return;
		}

		foreach ($blocks as $block)
		{
			$id = is_array($block) ? ($block['id'] ?? null) : null;
			$type = is_array($block) ? ($block['type'] ?? null) : null;
			if (!is_string($id) || $id === '' || !is_string($type) || $type === '')
			{
				continue;
			}

			// The first block of an id wins, exactly as the main pass reads it: a repeated id is refused there,
			// and answering the second occurrence here would make the outputs depend on which of them asked.
			$this->blocksById[$id] ??= ['type' => $type, 'properties' => self::propertiesOf($block)];
		}
	}

	/**
	 * The output ids of the block, or null when they cannot be listed at all: the type declares a marker whose
	 * value the instance does not carry, the block is not in the graph, or its type is unknown. Whether null is
	 * the honest declaration above or a failure of the provider is told apart by {@see self::hasFailed()}.
	 *
	 * @return list<string>|null
	 */
	public function outputsOf(string $blockId): ?array
	{
		return $this->resolutionOf($blockId)['outputs'];
	}

	/**
	 * The half of the package the DESCRIPTION of the type carries - its declared `RETURN` - whether or not the
	 * rest of it could be expanded. Empty when the type is unknown, the block is not in the graph or the type
	 * declares no returns at all, so nothing is admitted by a package that does not exist.
	 *
	 * @return list<string>
	 */
	public function knownOutputsOf(string $blockId): array
	{
		return $this->resolutionOf($blockId)['known'];
	}

	/** Whether the provider was asked about this block and threw, which leaves the graph judged on nothing. */
	public function hasFailed(string $blockId): bool
	{
		return $this->resolutionOf($blockId)['failed'];
	}

	/**
	 * @return array{outputs: list<string>|null, known: list<string>, failed: bool}
	 */
	private function resolutionOf(string $blockId): array
	{
		if (isset($this->resolutions[$blockId]))
		{
			return $this->resolutions[$blockId];
		}

		$block = $this->blocksById[$blockId] ?? null;
		if ($block === null)
		{
			return $this->resolutions[$blockId] = ['outputs' => null, 'known' => [], 'failed' => false];
		}

		try
		{
			$description = ($this->descriptionProvider)($block['type']);
		}
		catch (\Throwable)
		{
			return $this->resolutions[$blockId] = ['outputs' => null, 'known' => [], 'failed' => true];
		}

		return $this->resolutions[$blockId] = self::packageOf($description, $block['properties'], $block['type']);
	}

	/**
	 * @return array{outputs: list<string>|null, known: list<string>, failed: bool}
	 */
	private static function packageOf(?array $description, array $properties, string $type): array
	{
		if ($description === null)
		{
			// A type the catalog cannot name is refused on its own account (BlockTypeUnknown), so nothing is
			// claimed about its outputs here: naming a second, dependent cause would send the agent after a
			// problem it does not have.
			return ['outputs' => null, 'known' => [], 'failed' => false];
		}

		$declared = [];
		foreach (array_keys((array)($description['RETURN'] ?? [])) as $id)
		{
			$declared[(string)$id] = true;
		}

		$derived = self::DERIVED_MARKERS[mb_strtolower($type)] ?? null;
		$outputs = $declared;
		$complete = true;
		foreach ((array)($description['ADDITIONAL_RESULT'] ?? []) as $markerKey)
		{
			if ($derived !== null && $markerKey === $derived['marker'])
			{
				foreach (self::derivedKeysOf($properties[$derived['from']] ?? null, $derived['key']) as $key)
				{
					$declared[$key] = true;
					$outputs[$key] = true;
				}

				$complete = false;

				continue;
			}

			$marked = is_string($markerKey) ? ($properties[$markerKey] ?? null) : null;
			if (!is_array($marked))
			{
				$complete = false;

				continue;
			}

			foreach (array_keys($marked) as $id)
			{
				$outputs[(string)$id] = true;
			}
		}

		return [
			'outputs' => $complete ? array_keys($outputs) : null,
			'known' => array_keys($declared),
			'failed' => false,
		];
	}

	/**
	 * The keys a derived marker will hold, read off the setting it is built of: a list of objects, each naming one
	 * under $keyName. The legacy dialog of a storage create node also accepts the list as a JSON string, and a
	 * template saved that way reaches this class unchanged, so that form is read too.
	 *
	 * @return list<string>
	 */
	private static function derivedKeysOf(mixed $value, string $keyName): array
	{
		if (is_string($value))
		{
			$value = json_decode($value, true);
		}

		if (!is_array($value))
		{
			return [];
		}

		$keys = [];
		foreach ($value as $item)
		{
			$key = is_array($item) ? ($item[$keyName] ?? null) : null;
			if (is_string($key) && $key !== '')
			{
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * The properties of the submitted block, keyed the way an activity holds them: the settings of the contour
	 * are a list of name/value pairs, and a marker points at one of those names.
	 */
	private static function propertiesOf(array $block): array
	{
		$properties = [];
		foreach ((array)($block['settings'] ?? []) as $setting)
		{
			$name = is_array($setting) ? ($setting['name'] ?? null) : null;
			if (is_string($name) && $name !== '')
			{
				$properties[$name] = $setting['value'] ?? null;
			}
		}

		return $properties;
	}
}
