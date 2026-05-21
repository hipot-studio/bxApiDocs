<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Provider;

use Bitrix\Anonymizer\Replacement;
use Bitrix\DocumentGenerator\Body\Data\DocxNodesDto;
use Bitrix\DocumentGenerator\Body\Data\XmlNodesDto;

// dbg do
class DocxReplacer
{
	/** @var DocxNodesDto[] */
	private array $nodes;

	private int $position;
	private string $currentNodeValue;
	private Replacement\ReplacementDto $currentReplacement;
	private bool $replaceStarted = false;

	/**
	 * @param DocxNodesDto[] $nodes
	 */
	public function __construct(array $nodes)
	{
		$this->nodes = $nodes;
	}

	/**
	 * @return DocxNodesDto[]
	 */
	public function getNodes(): array
	{
		return $this->nodes;
	}

	/**
	 * @param Replacement\Storage $replacementStorage
	 * @return bool
	 */
	public function applyReplacements(Replacement\Storage $replacementStorage): bool
	{
		$this->position = 0;

		$changed = false;
		$replacements = $replacementStorage->sortByPosition()->getReplacements();
		if (empty($replacements))
		{
			return $changed;
		}
		$this->currentReplacement = array_shift($replacements);

		foreach ($this->nodes as $docNodes)
		{
			foreach ($docNodes->nodes as $node)
			{
				$this->currentNodeValue = trim($node->value);
				if (empty($this->currentNodeValue))
				{
					continue;
				}

				$useNextReplacement = true;
				while ($useNextReplacement)
				{
					$useNextReplacement = false;
					if (
						$this->replaceStarted
						|| $this->isReplaceStart($this->currentReplacement)
					)
					{
						$changed = true;
						$this->replaceNode($node, $this->currentReplacement);
					}

					if ($this->isReplaceEnd($this->currentReplacement))
					{
						unset($this->currentReplacement);
						if (empty($replacements))
						{
							return $changed;
						}
					}

					if (!isset($this->currentReplacement))
					{
						$this->currentReplacement = array_shift($replacements);
						$useNextReplacement = $this->isReplaceStart($this->currentReplacement);
					}
				}

				$this->position += mb_strlen($this->currentNodeValue) + 1;
			}
		}

		return $changed;
	}

	private function isReplaceStart(Replacement\ReplacementDto $replacement): bool
	{
		return
			$replacement->start >= $this->position
			&& $replacement->start <= $this->position + mb_strlen($this->currentNodeValue);
	}

	private function isReplaceEnd(Replacement\ReplacementDto $replacement): bool
	{
		return $replacement->end <= $this->position + mb_strlen($this->currentNodeValue) + 1;
	}

	private function replaceNode(XmlNodesDto $node, Replacement\ReplacementDto $replacement): void
	{
		$startAtThisNode = $replacement->start >= $this->position;
		$endAtThisNode = $replacement->end <= ($this->position + mb_strlen($this->currentNodeValue) + 1);

		if ($startAtThisNode && $endAtThisNode)
		{
			$node->value = str_replace($replacement->text, $replacement->getReplace(), $node->value);

			return;
		}

		// second nodes
		if ($startAtThisNode)
		{
			$this->replaceStarted = true;
		}

		$replacementEnd = min(($replacement->end - $this->position), mb_strlen($this->currentNodeValue));
		$replacedChunk = mb_substr($this->currentNodeValue, ($replacement->start - $this->position), $replacementEnd);
		$this->currentReplacement->text = str_replace($replacedChunk, '', $replacement->text);

		if ($startAtThisNode)
		{
			$node->value = str_replace($replacedChunk, $replacement->getReplace(), $node->value);
		}
		elseif ($endAtThisNode)
		{
			$this->replaceStarted = false;
			$node->value = str_replace($replacedChunk, '', $node->value);
		}
		// middle node
		else
		{
			$node->value = null;
		}
	}
}