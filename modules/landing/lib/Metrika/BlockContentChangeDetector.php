<?php
declare(strict_types=1);

namespace Bitrix\Landing\Metrika;

use Bitrix\Landing\Block;
use Bitrix\Landing\File;
use Bitrix\Landing\Node;

/**
 * Tells which kinds of content a block save has really changed.
 *
 * Snapshots are taken from the DOM already loaded for the save, node values are read by the same
 * handler that writes them - otherwise the values before and after the save are not comparable.
 * Text is reported only when the block as a whole has changed at least the threshold of characters,
 * the other kinds are reported by the fact of the change.
 */
class BlockContentChangeDetector
{
	/**
	 * Minimal amount of changed characters of text in the block to report the text kind.
	 */
	private const TEXT_CHANGE_THRESHOLD = 3;

	/**
	 * Size of a text value in bytes from which the characters are not compared one by one: splitting
	 * a string into an array of characters costs about a hundred bytes per character, and a text of
	 * this size is edited by whole fragments, so any difference in it is a change of the text anyway.
	 */
	private const MAX_COMPARED_TEXT_BYTES = 65536;

	/**
	 * Selector the editor sends instead of the real one for the wrapper node of the block.
	 */
	private const WRAPPER_SELECTOR = '#wrapper';

	/**
	 * Subtype of the block that carries an embedded CRM form.
	 */
	private const FORM_SUBTYPE = 'form';

	/**
	 * Node types that can produce a content kind. The rest of the manifest is not read at all.
	 */
	private const TRACKED_NODE_TYPES = [
		Node\Type::TEXT,
		Node\Type::IMAGE,
		Node\Type::STYLE_IMAGE,
	];

	/**
	 * Reads the current content of the block for the further comparison.
	 *
	 * @param Block $block Block with the DOM already loaded for the save.
	 * @param array|null $selectors Selectors touched by the save, null means all of them.
	 * @param array|null $manifest Manifest of the block, read from the block when not given.
	 *
	 * @return array<string, array> Values by manifest selector.
	 */
	public function takeSnapshot(Block $block, ?array $selectors = null, ?array $manifest = null): array
	{
		$manifest ??= $block->getManifest();
		$snapshot = [];
		$blockFiles = null;
		$blockFilesLoaded = false;

		foreach ((array)($manifest['nodes'] ?? []) as $selector => $node)
		{
			$type = (string)($node['type'] ?? '');
			if (
				!in_array($type, self::TRACKED_NODE_TYPES, true)
				|| !$this->isNodeRequested((string)$selector, (array)$node, $selectors)
			)
			{
				continue;
			}

			if ($type === Node\Type::STYLE_IMAGE && !$blockFilesLoaded)
			{
				// every style-image node filters its value by the files of the block, and the list is
				// the same for all selectors of this snapshot, so it is read once here instead of once
				// per node - the next snapshot reads it again, after the save may have changed it
				$blockFiles = File::getFilesFromBlock($block->getId());
				$blockFilesLoaded = true;
			}

			$snapshot[$selector] = $this->readNode($block, (string)$selector, $type, $blockFiles);
		}

		foreach ($this->getCrmFormSelectors($manifest) as $selector)
		{
			if (isset($snapshot[$selector]))
			{
				// the same selector can be a node of the manifest and an attribute holder of the form at
				// once, and the snapshot keeps a single value per selector. detect() reads the kind from
				// the type of the node - its branches go before isCrmFormNode - so the value read by the
				// node handler is the one comparable there, and the markup must not replace it
				continue;
			}

			if ($selectors === null || in_array($selector, $selectors, true))
			{
				$snapshot[$selector] = $this->readMarkup($block, $selector);
			}
		}

		return $snapshot;
	}

	/**
	 * @param array<string, array> $snapshotBefore
	 * @param array<string, array> $snapshotAfter
	 * @param array $manifest Manifest of the saved block.
	 *
	 * @return list<BlockContentKinds>
	 */
	public function detect(array $snapshotBefore, array $snapshotAfter, array $manifest): array
	{
		$changedKinds = [];
		$textDelta = 0;

		$selectors = array_unique(array_merge(array_keys($snapshotBefore), array_keys($snapshotAfter)));
		foreach ($selectors as $selector)
		{
			$old = (array)($snapshotBefore[$selector] ?? []);
			$new = (array)($snapshotAfter[$selector] ?? []);
			if ($old == $new)
			{
				continue;
			}

			$type = (string)($manifest['nodes'][$selector]['type'] ?? '');
			if ($type === Node\Type::TEXT)
			{
				$textDelta += $this->countChangedChars($old, $new);
			}
			elseif ($type === Node\Type::IMAGE || $type === Node\Type::STYLE_IMAGE)
			{
				$changedKinds[BlockContentKinds::Image->value] = true;
			}
			elseif ($this->isCrmFormNode((string)$selector, $manifest))
			{
				$changedKinds[BlockContentKinds::Form->value] = true;
			}
		}

		if ($textDelta >= self::TEXT_CHANGE_THRESHOLD)
		{
			$changedKinds[BlockContentKinds::Text->value] = true;
		}

		return array_values(array_filter(
			BlockContentKinds::cases(),
			static fn(BlockContentKinds $kind): bool => isset($changedKinds[$kind->value]),
		));
	}

	/**
	 * The editor addresses the wrapper node of the block by its own selector.
	 */
	private function isNodeRequested(string $selector, array $node, ?array $selectors): bool
	{
		if ($selectors === null || in_array($selector, $selectors, true))
		{
			return true;
		}

		return ($node['isWrapper'] ?? false) === true && in_array(self::WRAPPER_SELECTOR, $selectors, true);
	}

	/**
	 * @param int[]|null $blockFiles File ids of the block, preloaded once for the whole snapshot and
	 *        used by the style-image node; other node types do not read the files.
	 */
	private function readNode(Block $block, string $selector, string $type, ?array $blockFiles = null): array
	{
		try
		{
			if ($type === Node\Type::STYLE_IMAGE)
			{
				return (array)Node\StyleImg::getNode($block, $selector, $blockFiles);
			}

			/** @var \Bitrix\Landing\Node $className */
			$className = Node\Type::getClassName($type);

			return (array)$className::getNode($block, $selector);
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	/**
	 * A CRM form keeps its settings in the attributes of its node, so the whole markup of the node
	 * is the only value comparable before and after the save.
	 */
	private function readMarkup(Block $block, string $selector): array
	{
		$values = [];
		foreach ($block->getDom()->querySelectorAll($selector) as $pos => $node)
		{
			$values[$pos] = (string)$node->getOuterHTML();
		}

		return $values;
	}

	/**
	 * @return list<string>
	 */
	private function getCrmFormSelectors(array $manifest): array
	{
		if (!$this->hasFormSubtype($manifest))
		{
			return [];
		}

		return array_map('strval', array_keys((array)($manifest['attrs'] ?? [])));
	}

	private function isCrmFormNode(string $selector, array $manifest): bool
	{
		return $this->hasFormSubtype($manifest) && isset($manifest['attrs'][$selector]);
	}

	private function hasFormSubtype(array $manifest): bool
	{
		return in_array(self::FORM_SUBTYPE, (array)($manifest['block']['subtype'] ?? []), true);
	}

	/**
	 * @param array $old Values of the node by position.
	 * @param array $new Values of the node by position.
	 */
	private function countChangedChars(array $old, array $new): int
	{
		$changed = 0;
		foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $position)
		{
			$changed += $this->changedChars(
				$this->toText($old[$position] ?? ''),
				$this->toText($new[$position] ?? ''),
			);
		}

		return $changed;
	}

	/**
	 * Amount of characters the edit has touched: everything but the common prefix and suffix. The
	 * comparison is a bounded pass over the bytes - the strings are never split into arrays of
	 * characters - and it stops as soon as the threshold is reached, because the caller only needs to
	 * tell the count from the threshold, not to know it exactly above it.
	 */
	private function changedChars(string $old, string $new): int
	{
		if ($old === $new)
		{
			return 0;
		}

		$oldLength = strlen($old);
		$newLength = strlen($new);
		if (
			$oldLength > self::MAX_COMPARED_TEXT_BYTES
			|| $newLength > self::MAX_COMPARED_TEXT_BYTES
		)
		{
			return self::TEXT_CHANGE_THRESHOLD;
		}

		$prefix = $this->commonPrefixLength($old, $new, $oldLength, $newLength);
		$suffix = $this->commonSuffixLength($old, $new, $oldLength, $newLength, $prefix);

		return max(
			$this->countCharsInRange($old, $prefix, $oldLength - $suffix),
			$this->countCharsInRange($new, $prefix, $newLength - $suffix),
		);
	}

	/**
	 * Length in bytes of the common prefix of the strings, snapped back to a character boundary so a
	 * multibyte sequence is never counted as changed only because it was cut in half.
	 */
	private function commonPrefixLength(string $old, string $new, int $oldLength, int $newLength): int
	{
		$limit = min($oldLength, $newLength);
		$prefix = 0;
		while ($prefix < $limit && $old[$prefix] === $new[$prefix])
		{
			$prefix++;
		}

		while ($prefix > 0 && $prefix < $oldLength && $this->isUtf8Continuation($old[$prefix]))
		{
			$prefix--;
		}

		return $prefix;
	}

	/**
	 * Length in bytes of the common suffix of the strings, not overlapping the prefix and snapped
	 * forward to a character boundary so a multibyte sequence is never counted as changed only because
	 * it was cut in half.
	 */
	private function commonSuffixLength(string $old, string $new, int $oldLength, int $newLength, int $prefix): int
	{
		$limit = min($oldLength, $newLength) - $prefix;
		$suffix = 0;
		while ($suffix < $limit && $old[$oldLength - 1 - $suffix] === $new[$newLength - 1 - $suffix])
		{
			$suffix++;
		}

		while ($suffix > 0 && $this->isUtf8Continuation($old[$oldLength - $suffix]))
		{
			$suffix--;
		}

		return $suffix;
	}

	/**
	 * Amount of characters in the byte range [$from, $to) of the string, counted up to the threshold
	 * and stopped there. A character is counted by its leading byte; the range is assumed to start and
	 * end on a character boundary, which the prefix and suffix lengths guarantee.
	 */
	private function countCharsInRange(string $value, int $from, int $to): int
	{
		$count = 0;
		for ($i = $from; $i < $to && $count < self::TEXT_CHANGE_THRESHOLD; $i++)
		{
			if (!$this->isUtf8Continuation($value[$i]))
			{
				$count++;
			}
		}

		return $count;
	}

	private function isUtf8Continuation(string $byte): bool
	{
		return (ord($byte) & 0xC0) === 0x80;
	}

	private function toText(mixed $value): string
	{
		return is_scalar($value) ? (string)$value : '';
	}
}
