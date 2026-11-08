<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;

/**
 * Passthrough settings an agent may only re-send verbatim, and the fingerprint that decides "verbatim".
 *
 * A passthrough setting has no typed passport, so its value reaches the template exactly as sent (ALG-03 never
 * fires and the name check lets it by). For most of them that is the point - it is how a control node survives
 * the round-trip. For a few the payload is executable, and the activity says so itself through
 * `getAiRestrictedSettingNames()`: today that is the ifelse branch condition whose class requires admin rights to
 * author (`codecondition`). Publishing still re-checks those rights, so this is not a privilege escalation - it
 * closes the confused-deputy gap where an external model composes the payload and the administrator publishes it
 * seeing an ordinary "Condition" node on the canvas.
 *
 * The names are asked of the activity per block type and memoized: resolving them loads condition files, while a
 * single reverse call fingerprints every block of the graph.
 */
final class RestrictedSettingRegistry
{
	/** @var array<string, list<string>> lower-cased setting names per "type|module|entity|documentType" */
	private array $namesCache = [];

	/**
	 * @return list<string> lower-cased setting names, empty for a type that declares none
	 */
	public function namesFor(string $type, DocumentDescription $documentType): array
	{
		$cacheKey = mb_strtolower($type) . '|' . implode('|', $documentType->toBizprocComplexType());
		if (isset($this->namesCache[$cacheKey]))
		{
			return $this->namesCache[$cacheKey];
		}

		$this->namesCache[$cacheKey] = $this->resolveNames($type, $documentType);

		return $this->namesCache[$cacheKey];
	}

	/**
	 * Whether the value holds nothing for the baseline to authorise. A properties dialog writes its whole map on
	 * every save, filled controls and unfilled ones alike, so a restricted setting arrives empty far more often
	 * than it arrives written - and an empty one carries no payload, which is the only thing the barrier guards.
	 *
	 * Emptiness is spelled out rather than delegated to `empty()` or {@see \CBPHelper::isEmptyValue()}: '0' and 0
	 * are values a condition decides by, and reading them as nothing would let an authored payload past.
	 */
	public static function carriesNoPayload(mixed $value): bool
	{
		return $value === null || $value === '' || $value === [];
	}

	/**
	 * The comparison unit is the (name, value) pair rather than the whole node: a legitimate edit elsewhere on the
	 * branch - a retitled node, a reconnected port - must not cost the template its existing condition.
	 */
	public static function fingerprintOf(string $name, mixed $value): string
	{
		return hash('sha256', serialize([
			'name' => mb_strtolower($name),
			'value' => AgentSettingValueCanonicalizer::canonicalize($value, false),
		]));
	}

	/**
	 * Anything but "no match" - a hit or PCRE giving up - keeps the type out: the type names the activity file to
	 * include, so a verdict the engine could not reach must not be read as a clean name.
	 *
	 * @return list<string>
	 */
	private function resolveNames(string $type, DocumentDescription $documentType): array
	{
		if ($type === '' || preg_match('#[^a-zA-Z0-9_]#', $type) !== 0)
		{
			return [];
		}

		try
		{
			if (!\CBPRuntime::getRuntime()->includeActivityFile($type))
			{
				return [];
			}

			$names = \CBPActivity::callStaticMethod(
				$type,
				'getAiRestrictedSettingNames',
				[$documentType->toBizprocComplexType()],
			);
		}
		catch (\Throwable)
		{
			return [];
		}

		if (!is_array($names))
		{
			return [];
		}

		$normalized = [];
		foreach ($names as $name)
		{
			if (is_string($name) && $name !== '')
			{
				$normalized[] = mb_strtolower($name);
			}
		}

		return array_values(array_unique($normalized));
	}

	/**
	 * A restricted value may be re-sent only at the location that published it. The location belongs to the graph
	 * model, while the activity type and setting name protect against a same-named value crossing activity classes.
	 */
	public static function referenceFingerprint(string $location, string $type, string $name, mixed $value): string
	{
		return hash('sha256', serialize([
			'location' => $location,
			'type' => mb_strtolower($type),
			'name' => mb_strtolower($name),
			'value' => AgentSettingValueCanonicalizer::canonicalize($value, false),
		]));
	}
}
