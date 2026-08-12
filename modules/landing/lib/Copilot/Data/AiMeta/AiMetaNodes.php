<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Data\AiMeta;

final class AiMetaNodes
{
	/** @var list<AiMetaNode> */
	private array $nodes = [];

	/**
	 * @param list<AiMetaNode> $nodes
	 */
	public function __construct(array $nodes = [])
	{
		$this->nodes = $nodes;
	}

	public static function fromArray(mixed $data): self
	{
		if (!is_array($data))
		{
			return new self();
		}

		$nodes = [];
		foreach ($data as $node)
		{
			$aiMetaNode = AiMetaNode::fromArray($node);
			if ($aiMetaNode !== null)
			{
				$nodes[] = $aiMetaNode;
			}
		}

		return new self($nodes);
	}

	public static function fromManifestNodes(array $nodes): self
	{
		$result = [];
		foreach ($nodes as $selector => $node)
		{
			if (!is_array($node))
			{
				continue;
			}

			$aiMetaNode = AiMetaNode::fromArray([
				'nodeCode' => (string)$selector,
				'type' => $node['type'] ?? '',
				'name' => $node['name'] ?? '',
			]);
			if ($aiMetaNode !== null)
			{
				$result[] = $aiMetaNode;
			}
		}

		return new self($result);
	}

	public function toArray(): array
	{
		return array_map(static fn(AiMetaNode $node): array => $node->toArray(), $this->nodes);
	}

	/**
	 * @return list<AiMetaNode>
	 */
	public function getNodes(): array
	{
		return $this->nodes;
	}
}
