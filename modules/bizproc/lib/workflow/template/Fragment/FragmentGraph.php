<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Fragment;

use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;

/**
 * What the graph of a fragment answers about itself: which links survive the nodes that stayed, and which
 * node of the top level a nested one stands under.
 *
 * Both answers know the same two things - the format of an end of a link and the fact that a node carries
 * its own subtree - so they live in one place: the filter of the applicability, the barrier of the settings
 * and the answer of a prepared insertion all ask them, and a copy per caller would drift apart at the first
 * change of either format.
 */
final class FragmentGraph
{
	/**
	 * A link travels only while both of its ends are still in the fragment. An end carries the port after a
	 * colon, so the name of the node is what stands before the first one
	 * ({@see NodesToTemplate::LINK_DELIMITER}).
	 */
	public static function keepLinksBetween(array $links, array $keptNodes): array
	{
		$names = [];
		foreach ($keptNodes as $node)
		{
			$name = (string)($node[NodesToTemplate::ELEMENT_NAME] ?? '');
			if ($name !== '')
			{
				$names[$name] = true;
			}
		}

		return array_values(array_filter(
			$links,
			static fn($link): bool => is_array($link)
				&& isset(
					$names[self::endName($link[0] ?? null)],
					$names[self::endName($link[1] ?? null)],
				)
			,
		));
	}

	/**
	 * The node of the TOP LEVEL every name of a fragment belongs to, the nested ones included; a node of the
	 * top level answers with itself.
	 *
	 * Only the activities of the top level become blocks of the canvas
	 * ({@see \Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes::createBlocks()}), so an address the
	 * recipient of an insertion can be sent to is always one of these.
	 *
	 * @param array $nodes activities as the envelope carries them
	 *
	 * @return array<string, string> the name of the outer node, by the name of any node of the fragment
	 */
	public static function outerNodeNames(array $nodes): array
	{
		$outer = [];

		foreach ($nodes as $node)
		{
			if (!is_array($node))
			{
				continue;
			}

			$name = (string)($node[NodesToTemplate::ELEMENT_NAME] ?? '');
			if ($name !== '')
			{
				self::collectUnder($node, $name, $outer);
			}
		}

		return $outer;
	}

	/**
	 * @param array<string, string> $outer
	 */
	private static function collectUnder(array $node, string $outerName, array &$outer): void
	{
		$name = (string)($node[NodesToTemplate::ELEMENT_NAME] ?? '');
		if ($name !== '' && !isset($outer[$name]))
		{
			$outer[$name] = $outerName;
		}

		$children = $node[NodesToTemplate::ELEMENT_CHILDREN] ?? null;
		if (!is_array($children))
		{
			return;
		}

		foreach ($children as $child)
		{
			if (is_array($child))
			{
				self::collectUnder($child, $outerName, $outer);
			}
		}
	}

	private static function endName(mixed $end): string
	{
		$name = strstr((string)$end, NodesToTemplate::LINK_DELIMITER, true);

		return $name === false ? (string)$end : $name;
	}
}
