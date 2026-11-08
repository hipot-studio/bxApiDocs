<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

/**
 * Label registry of one template schema, keyed by \Bitrix\Bizproc\Workflow\Template\SourceType and
 * read by {@see \Bitrix\Bizproc\Activity\ReadableExpressionResolver}.
 *
 * A label free to declare is declared upfront; a label that costs a read is left to a deferred
 * resolver, and both what it read and what it missed are remembered, so no reference pays twice.
 * An empty label counts as no label. Display-only; never persisted.
 */
final class ExpressionLabelDictionary
{
	/** @var array<string, array<string, string>> source type => (key => label) */
	private array $labels = [];

	/** @var array<string, callable(string): array<string, string>> source type => deferred resolver */
	private array $deferredResolvers = [];

	/** @var array<string, array<string, true>> source type => keys the deferred resolver found no label for */
	private array $deferredMisses = [];

	public static function makeActivityOutputKey(string $activityName, string $outputId): string
	{
		return $activityName . ':' . $outputId;
	}

	public function declare(string $sourceType, string $key, string $label): void
	{
		if ($sourceType === '' || $key === '' || $label === '')
		{
			return;
		}

		$this->labels[$sourceType][$key] = $label;
	}

	/**
	 * Registers the source of labels too expensive to collect upfront: the resolver takes the missing key
	 * and answers with every label it read on the way, keyed the way declare() keys them.
	 *
	 * @param callable(string): array<string, string> $resolver
	 */
	public function declareDeferred(string $sourceType, callable $resolver): void
	{
		$this->deferredResolvers[$sourceType] = $resolver;
	}

	public function resolve(string $sourceType, string $key): ?string
	{
		$label = $this->labels[$sourceType][$key] ?? null;
		if ($label !== null)
		{
			return $label;
		}

		if (isset($this->deferredMisses[$sourceType][$key]) || !isset($this->deferredResolvers[$sourceType]))
		{
			return null;
		}

		foreach (($this->deferredResolvers[$sourceType])($key) as $resolvedKey => $resolvedLabel)
		{
			$this->declare($sourceType, (string)$resolvedKey, (string)$resolvedLabel);
		}

		$label = $this->labels[$sourceType][$key] ?? null;
		if ($label === null)
		{
			$this->deferredMisses[$sourceType][$key] = true;
		}

		return $label;
	}
}
