<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Graph;

/**
 * Directed reachability over the agent connection list, the model the storage order rule of the external contour
 * is proven on (ALG-03): a connection is a step of the control flow, so "the creator runs before the consumer"
 * is "a directed path leads from the creator to the consumer".
 *
 * Kept apart from {@see UndirectedConnectivity}, which answers a different question on the same edge list - frame
 * membership is a grouping and knows no direction. Both read the very shape the connections validator accepts, so
 * neither can judge a graph the forward path would refuse.
 *
 * The adjacency is built once and the instance is immutable, because one run asks several questions of it - one
 * pair of traversals per storage code - and a rebuild per question would walk the whole list again.
 */
final class DirectedReachability
{
	/**
	 * Node types whose control transfer is NOT written in the connections: the state nodes name their target by a
	 * state name and the runtime jumps there ({@see \CBPSetStateNode::execute()}), so a graph carrying one may run
	 * in an order no edge of it states. A proof resting on the edges alone is therefore incomplete on such a graph,
	 * which is what the caller turns into a warning instead of a refusal - the model is short, not the graph.
	 *
	 * Lower-cased catalog type codes, matched the way every type in this contour is.
	 */
	private const ROUTING_OPAQUE_TYPES = [
		'setstatenode',
		'statenode',
		'setstateactivity',
	];

	/**
	 * @param array<string, list<string>> $adjacency destination block ids per source block id
	 * @param array<string, true> $blocksWithIncomingEdge every block some connection leads into
	 */
	private function __construct(
		private readonly array $adjacency,
		private readonly array $blocksWithIncomingEdge,
	) {}

	/**
	 * Each connection is a `{sourceBlockId, destinationBlockId}` shape; both endpoints must be non-empty strings.
	 * Malformed entries are skipped, exactly as the undirected model skips them: a connection the forward
	 * validator refuses must not decide an order question either.
	 */
	public static function fromConnections(mixed $connections): self
	{
		$adjacency = [];
		$blocksWithIncomingEdge = [];
		if (!is_array($connections))
		{
			return new self($adjacency, $blocksWithIncomingEdge);
		}

		foreach ($connections as $connection)
		{
			if (!is_array($connection))
			{
				continue;
			}

			$source = $connection['sourceBlockId'] ?? null;
			$destination = $connection['destinationBlockId'] ?? null;
			if (!is_string($source) || $source === '' || !is_string($destination) || $destination === '')
			{
				continue;
			}

			$adjacency[$source][] = $destination;
			$blocksWithIncomingEdge[$destination] = true;
		}

		return new self($adjacency, $blocksWithIncomingEdge);
	}

	/**
	 * Every block a directed path leads to from one of the sources, the sources themselves included (a path of no
	 * steps). A blocked block is neither reached nor walked through: that is what makes the set answer "can the
	 * flow get there WITHOUT passing any of these", the question a bypass of the storage creators is.
	 *
	 * @param list<string> $sourceBlockIds
	 * @param array<string, true> $blockedBlockIds
	 * @return array<string, true>
	 */
	public function reachableFrom(array $sourceBlockIds, array $blockedBlockIds = []): array
	{
		$reached = [];
		$stack = [];
		foreach ($sourceBlockIds as $sourceBlockId)
		{
			if (!isset($blockedBlockIds[$sourceBlockId]))
			{
				$stack[] = $sourceBlockId;
			}
		}

		while ($stack !== [])
		{
			$blockId = array_pop($stack);
			if (isset($reached[$blockId]))
			{
				continue;
			}

			$reached[$blockId] = true;
			foreach ($this->adjacency[$blockId] ?? [] as $next)
			{
				if (!isset($reached[$next]) && !isset($blockedBlockIds[$next]))
				{
					$stack[] = $next;
				}
			}
		}

		return $reached;
	}

	/**
	 * The blocks the flow can start at: those no connection leads into. A graph whose every block has an incoming
	 * edge - one closed cycle - has no entry at all and reaches nothing, which is the honest answer for a graph
	 * the runtime would never enter either.
	 *
	 * @param list<string> $blockIds blocks that may carry connections at all (frame overlays own no ports)
	 * @return list<string>
	 */
	public function entryPoints(array $blockIds): array
	{
		$entryPoints = [];
		foreach ($blockIds as $blockId)
		{
			if (!isset($this->blocksWithIncomingEdge[$blockId]))
			{
				$entryPoints[] = $blockId;
			}
		}

		return $entryPoints;
	}

	/**
	 * Whether a block of this type moves control by something other than a connection, which leaves every verdict
	 * built on the edges unproven ({@see self::ROUTING_OPAQUE_TYPES}).
	 */
	public static function routesOutsideConnections(string $blockType): bool
	{
		return in_array(mb_strtolower($blockType), self::ROUTING_OPAQUE_TYPES, true);
	}
}
