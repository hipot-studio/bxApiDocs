<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Graph;

/**
 * Which blocks of the agent graph run before a given one, and which ids the graph carries at all - the model the
 * expression reference rule of the external contour is judged on (BRIEF § 4.2). A reference `{=<blockId>:<output>}`
 * reads a value another block has already produced, so the manual editor offers the author the ANCESTORS of the
 * node being edited and nothing else; the contour that writes the same graph without the editor is held to the
 * same rule, or it composes a template whose value is read before it exists.
 *
 * Kept apart from {@see DirectedReachability}, which answers the opposite direction of the same edge list - "what
 * does the flow reach from here" - and from {@see UndirectedConnectivity}, which knows no direction at all. The
 * three read the very shape the connections validator accepts, so none of them can judge a graph the forward path
 * would refuse.
 *
 * Built once per validation run: the block index and the reverse adjacency are assembled at construction, and the
 * ancestors of a block are walked on the first question about that block and answered from the memo afterwards.
 * That is what keeps a substitution from costing a traversal of its own - a single setting may carry a dozen of
 * them, and a graph may carry 256 blocks.
 */
final class AncestorIndex
{
	/** @var array<string, array<string, true>> ancestors per block id, memoised on the first question */
	private array $ancestorsByBlockId = [];

	/**
	 * @param array<string, true> $blockIds ids the submitted graph carries
	 * @param array<string, list<string>> $incomingAdjacency source block ids per destination block id
	 */
	private function __construct(
		private readonly array $blockIds,
		private readonly array $incomingAdjacency,
	) {}

	/**
	 * Reads the graph exactly as it was submitted, before any block is judged: the ids and the edges are what the
	 * agent WROTE, and a block the catalog turns down still occupies its id and its place in the flow. A malformed
	 * block or connection is skipped rather than guessed at - it is refused on its own account by
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentBlockValidator} and by
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentConnectionsValidator}.
	 */
	public static function fromGraph(mixed $blocks, mixed $connections): self
	{
		$blockIds = [];
		if (is_array($blocks))
		{
			foreach ($blocks as $block)
			{
				$id = is_array($block) ? ($block['id'] ?? null) : null;
				if (is_string($id) && $id !== '')
				{
					$blockIds[$id] = true;
				}
			}
		}

		$incomingAdjacency = [];
		if (is_array($connections))
		{
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

				$incomingAdjacency[$destination][] = $source;
			}
		}

		return new self($blockIds, $incomingAdjacency);
	}

	public function hasBlock(string $blockId): bool
	{
		return isset($this->blockIds[$blockId]);
	}

	/**
	 * Whether a directed path of at least one step leads from the candidate to the block. The step is what makes
	 * the answer honest inside a loop: a block of a closed cycle does run before itself, and the walk below
	 * reports it as its own ancestor because the edges say so, while a block outside any cycle never is.
	 */
	public function isAncestorOf(string $candidateBlockId, string $blockId): bool
	{
		return isset($this->ancestorsOf($blockId)[$candidateBlockId]);
	}

	/**
	 * @return array<string, true>
	 */
	private function ancestorsOf(string $blockId): array
	{
		if (isset($this->ancestorsByBlockId[$blockId]))
		{
			return $this->ancestorsByBlockId[$blockId];
		}

		$ancestors = [];
		$stack = $this->incomingAdjacency[$blockId] ?? [];
		while ($stack !== [])
		{
			$current = array_pop($stack);
			if (isset($ancestors[$current]))
			{
				continue;
			}

			$ancestors[$current] = true;
			foreach ($this->incomingAdjacency[$current] ?? [] as $previous)
			{
				if (!isset($ancestors[$previous]))
				{
					$stack[] = $previous;
				}
			}
		}

		return $this->ancestorsByBlockId[$blockId] = $ancestors;
	}
}
