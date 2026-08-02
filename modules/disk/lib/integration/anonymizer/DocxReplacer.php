<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\Anonymizer;

use Bitrix\Anonymizer\Internal\Entities\Replacement\ReplacementDto;
use Bitrix\Anonymizer\Internal\Services\Replacement\Storage;
use Bitrix\DocumentGenerator\Body\Data\DocxNodesDto;
use Bitrix\DocumentGenerator\Body\Data\XmlNodesDto;

/**
 * Applies replacement offsets (from flattened DOCX text) to document XML nodes.
 */
final class DocxReplacer
{
	/** @var DocxNodesDto[] */
	private array $nodes;

	private int $position;
	private string $currentNodeValue;
	private ReplacementDto $currentReplacement;
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

	public function applyReplacements(Storage $replacementStorage): bool
	{
		$this->position = 0;

		$changed = false;
		$replacements = $replacementStorage->sortByPosition()->getReplacements();
		if ($replacements === [])
		{
			return $changed;
		}

		$this->currentReplacement = array_shift($replacements);

		foreach ($this->nodes as $docNodes)
		{
			foreach ($docNodes->nodes as $node)
			{
				$this->currentNodeValue = trim($node->value);
				if ($this->currentNodeValue === '')
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
						if ($replacements === [])
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

	private function isReplaceStart(ReplacementDto $replacement): bool
	{
		return
			$replacement->start >= $this->position
			&& $replacement->start <= $this->position + mb_strlen($this->currentNodeValue);
	}

	private function isReplaceEnd(ReplacementDto $replacement): bool
	{
		return $replacement->end <= $this->position + mb_strlen($this->currentNodeValue) + 1;
	}

	private function replaceNode(XmlNodesDto $node, ReplacementDto $replacement): void
	{
		$startAtThisNode = $replacement->start >= $this->position;
		$endAtThisNode = $replacement->end <= ($this->position + mb_strlen($this->currentNodeValue) + 1);

		if ($startAtThisNode && $endAtThisNode)
		{
			$node->value = str_replace($replacement->text, $replacement->getReplace(), $node->value);

			return;
		}

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
		else
		{
			$node->value = null;
		}
	}
}
