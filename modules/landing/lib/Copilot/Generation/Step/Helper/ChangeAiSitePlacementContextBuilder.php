<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Helper;

final class ChangeAiSitePlacementContextBuilder
{
	public function build(array $item, array $candidates): string
	{
		$candidates = array_values(array_filter($candidates, 'is_array'));
		$placement = is_array($item['placement'] ?? null) ? $item['placement'] : [];
		$mode = trim((string)($placement['mode'] ?? 'append'));
		$blockId = (int)($placement['blockId'] ?? 0);
		if ($mode === 'append' || $blockId <= 0)
		{
			return $this->encode($this->buildContext('append', 0, null, $this->findLastCandidate($candidates), null));
		}
		if ($mode !== 'before')
		{
			$mode = 'after';
		}

		$targetIndex = $this->findCandidateIndex($candidates, $blockId);
		$target = $targetIndex !== null ? $candidates[$targetIndex] : null;
		$previous = null;
		$next = null;
		if ($targetIndex !== null)
		{
			if ($mode === 'before')
			{
				$previous = $candidates[$targetIndex - 1] ?? null;
				$next = $target;
			}
			else
			{
				$previous = $target;
				$next = $candidates[$targetIndex + 1] ?? null;
			}
		}

		return $this->encode($this->buildContext($mode, $blockId, $target, $previous, $next));
	}

	private function buildContext(
		string $mode,
		int $targetBlockId,
		?array $target,
		?array $previous,
		?array $next,
	): array
	{
		$context = [
			'mode' => $mode,
			'targetBlockId' => $targetBlockId,
			'targetAnchor' => $this->stringValue($target, 'anchor'),
			'targetSummary' => $this->stringValue($target, 'summary'),
			'previousBlockId' => (int)($previous['id'] ?? 0),
			'nextBlockId' => (int)($next['id'] ?? 0),
			'previousSummary' => $this->stringValue($previous, 'summary'),
			'nextSummary' => $this->stringValue($next, 'summary'),
			'previousStyleTokens' => $this->buildStyleTokens($previous),
			'nextStyleTokens' => $this->buildStyleTokens($next),
			'transitionHint' => $this->buildTransitionHint($mode, $previous, $next),
		];

		return $this->removeEmpty($context);
	}

	private function buildStyleTokens(?array $candidate): array
	{
		if (!$candidate)
		{
			return [];
		}

		$tokens = [
			'rootClasses' => $this->stringValue($candidate, 'rootClasses'),
			'fonts' => $this->listValue($candidate['dominantFonts'] ?? null),
			'colors' => $this->listValue($candidate['dominantColors'] ?? null),
			'imagePattern' => $this->stringValue($candidate, 'imagePattern'),
		];
		$styleTokens = is_array($candidate['styleTokens'] ?? null) ? $candidate['styleTokens'] : [];
		foreach (['surfaces', 'layout', 'cta'] as $group)
		{
			$tokens[$group] = $this->listValue($styleTokens[$group] ?? null);
		}

		return $this->removeEmpty($tokens);
	}

	private function buildTransitionHint(string $mode, ?array $previous, ?array $next): string
	{
		if ($previous && $next)
		{
			return 'Bridge previous and next sections: keep a natural background, spacing, container and CTA transition.';
		}
		if ($previous)
		{
			return $mode === 'append'
				? 'Continue the last section rhythm and make the new block feel like a natural ending.'
				: 'Continue the previous section rhythm while preparing a clean transition to the next block.'
			;
		}
		if ($next)
		{
			return 'Introduce the page style cleanly and transition into the next section without visual mismatch.';
		}

		return 'Use the global page style context as the main design source.';
	}

	private function findCandidateIndex(array $candidates, int $blockId): ?int
	{
		foreach ($candidates as $index => $candidate)
		{
			if ((int)($candidate['id'] ?? 0) === $blockId)
			{
				return (int)$index;
			}
		}

		return null;
	}

	private function findLastCandidate(array $candidates): ?array
	{
		for ($index = count($candidates) - 1; $index >= 0; $index--)
		{
			if (is_array($candidates[$index] ?? null))
			{
				return $candidates[$index];
			}
		}

		return null;
	}

	private function stringValue(?array $data, string $key): string
	{
		return trim((string)($data[$key] ?? ''));
	}

	private function listValue(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$items = [];
		foreach ($value as $item)
		{
			$item = trim((string)$item);
			if ($item !== '')
			{
				$items[] = $item;
			}
		}

		return array_values(array_unique($items));
	}

	private function removeEmpty(array $data): array
	{
		$result = [];
		foreach ($data as $key => $value)
		{
			if (is_array($value))
			{
				$value = $this->removeEmpty($value);
			}
			if ($value !== [] && $value !== '' && $value !== 0)
			{
				$result[$key] = $value;
			}
		}

		return $result;
	}

	private function encode(array $context): string
	{
		$encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return is_string($encoded) ? $encoded : '{"mode":"append"}';
	}
}
