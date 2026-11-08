<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSetting;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;

/**
 * Deterministic fingerprint of a storage node: a hash of its type and the canonical form of *all* its persisted
 * settings (ALG-01). The reverse pipeline compares an incoming node against the fingerprints of the published
 * baseline, so an unchanged round-trip node passes while any edited setting shifts the hash and is refused. Both
 * sides run this identical function, so the comparison stays symmetric.
 *
 * Canonical form: the type is lower-cased (an agent re-send may not be); settings are keyed by name and
	 * name-sorted; immutable nodes preserve their opaque settings verbatim, while mutable consumers use
	 * {@see AgentSettingValueCanonicalizer} to undo the typed USER/MAP normalizations the reverse validator applies
	 * only to the incoming side.
 */
final class StorageNodeFingerprint
{
	public static function of(string $type, AgentSettingCollection $settings): string
	{
		$canonical = [
			'type' => mb_strtolower($type),
			'settings' => self::canonicalizeSettings($type, $settings),
		];

		return hash('sha256', serialize($canonical));
	}

	/**
	 * A static storage reference is bound to the graph node that published it, not merely to an equal settings hash.
	 */
	public static function referenceOf(string $blockId, string $type, AgentSettingCollection $settings): string
	{
		return hash('sha256', serialize([$blockId, self::of($type, $settings)]));
	}

	/**
	 * Fingerprint of the columns a create node declares - the contract the storage code stands for (ALG-03). Two
	 * create nodes of one code are one contract when this value matches and a conflict when it does not, so what
	 * the function is allowed to fold together decides which graphs are refused:
	 *
	 *   - the columns are keyed by their own code and key-sorted, because the position of a column in the list
	 *     settles nothing at runtime: a column already present under its code is left as it is
	 *     ({@see \CBPCreateStorageNode::createStorageFields()}), so two nodes listing the same columns in two
	 *     orders create the same storage. Anchoring the contract on the array order would refuse them;
	 *   - `storageId` is dropped: the node resolves the storage from its own code while the process runs and
	 *     overwrites the value inside every element, so what an element carries there decides nothing.
	 *
	 * A value that is not a list of columns is not folded at all - it is fingerprinted verbatim, so such a node
	 * agrees only with a byte-for-byte twin instead of being read as declaring nothing.
	 */
	public static function columnContractOf(AgentSettingCollection $settings): string
	{
		$columns = StorageNodeClassifier::extractStorageColumns($settings);
		$readable = self::readColumnList($columns);

		return hash('sha256', serialize($readable ?? ['raw' => self::canonicalizeValue($columns)]));
	}

	/**
	 * The columns keyed by their own code and key-sorted, or null when the value is not a list of columns at all.
	 * A column carrying no code keeps a key of its own position: it is refused upstream by the schema, and merging
	 * every such column into one key would call two different lists one contract.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function readColumnList(mixed $columns): ?array
	{
		if (!is_array($columns) || !array_is_list($columns))
		{
			return null;
		}

		$byCode = [];
		foreach ($columns as $position => $column)
		{
			if (!is_array($column))
			{
				return null;
			}

			unset($column['storageId']);
			$code = $column['code'] ?? null;
			$key = is_scalar($code) ? 'code:' . $code : 'position:' . $position;
			$byCode[$key] = self::canonicalizeValue($column);
		}

		ksort($byCode);

		return $byCode;
	}

	/**
	 * Key order is the only thing folded here: the transport normalisations are deliberately left out, exactly as
	 * they are for the settings of an immutable node above - a create node carries its settings opaquely, and the
	 * collapse of a one-element list would read a list of one column as that column alone.
	 */
	private static function canonicalizeValue(mixed $value): mixed
	{
		return AgentSettingValueCanonicalizer::canonicalize($value, normalizeTransport: false);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function canonicalizeSettings(string $type, AgentSettingCollection $settings): array
	{
		$map = [];
		$normalizeTransport = !StorageNodeClassifier::isImmutable($type);
		foreach ($settings as $setting)
		{
			/** @var AgentSetting $setting */
			$map[$setting->name] = AgentSettingValueCanonicalizer::canonicalize(
				$setting->value,
				$normalizeTransport,
			);
		}
		ksort($map);

		return $map;
	}
}
