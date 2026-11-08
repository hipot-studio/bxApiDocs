<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\DarkTheme;

use Bitrix\Main\Config\Ini;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Decides whether a message body may be recolored for a dark theme.
 *
 * Only an exhausted processing budget or a blocking signal stops the recolor. Markup that merely looks
 * unusual - tables, style blocks, inline colors, external links, parse errors - is not a reason to give
 * the reader the original light body back.
 */
final class BlockingSignalDetector
{
	public const DOCUMENT_KEY = DocumentLoader::DOCUMENT_KEY;

	public const REASON_BUDGET_SIZE = 'BUDGET_SIZE';
	public const REASON_BUDGET_MARKUP = 'BUDGET_MARKUP';
	public const REASON_BUDGET_NODES = 'BUDGET_NODES';
	public const REASON_BUDGET_DEPTH = 'BUDGET_DEPTH';
	public const REASON_BUDGET_RULES = 'BUDGET_RULES';
	public const REASON_BUDGET_COLORS = 'BUDGET_COLORS';
	public const REASON_BUDGET_CSS_VOLUME = 'BUDGET_CSS_VOLUME';
	public const REASON_PARSE_FAILED = DocumentLoader::ERROR_PARSE_FAILED;
	public const REASON_UNSUPPORTED_DARK_MEDIA_CSS = 'UNSUPPORTED_DARK_MEDIA_CSS';

	private const MAX_BODY_SIZE = 512 * 1024;
	private const MAX_NODE_COUNT = 10000;
	private const MAX_MARKUP_TOKENS = self::MAX_NODE_COUNT;
	private const MAX_DEPTH = 100;
	private const MAX_CSS_DEPTH = 32;

	/**
	 * How deep the functions of a CSS value may nest.
	 *
	 * The readers of a value are written for the values letters are written with, and one of them - the
	 * unwrapping of a chain of custom property references - reads to a depth of its own and cannot answer
	 * beyond it. A value nested deeper than that is a value the recolor cannot read to the end, and an answer
	 * it cannot give is not an answer to guess: such a body goes back to its author whole.
	 *
	 * It is also the one budget that bounds the cost of those readers. Nothing else counts the nesting of a
	 * value - neither the size of the body nor the volume of the sheets is a bound on it - and a chain of a few
	 * thousand references in a message of twenty-six kilobytes is what a reader waits on. The bound is the
	 * depth the scanner unwraps, read from the scanner itself so the two cannot drift apart; the deepest value
	 * of the whole corpus of the stand nests two functions.
	 */
	private const MAX_CSS_VALUE_DEPTH = CssScanner::MAX_VARIABLE_DEPTH;
	private const MAX_CSS_DECLARATION_SEPARATORS = 10000;
	private const MAX_STYLE_RULES = 1000;
	private const MAX_UNIQUE_COLORS = 500;

	/**
	 * How much style-sheet text the recolor is allowed to scan in total.
	 *
	 * The recolor reads the text of a block once per level of nesting that holds it: the parser of the rewriter
	 * splits the body of every at-rule into segments of its own, and the pass that answers whether any rule
	 * paints an author picture walks the same nesting again. So the cost of one body is the product of the
	 * size of its sheets and their nesting, and neither the size budget nor the depth budget bounds a product.
	 * The measure below is that product itself - the sum of the nesting depth over the characters of every
	 * sheet - and it overstates the real work, because a plain rule costs one level of nesting the rewriter does
	 * not re-scan. The corpus of the stand fits in 41 000 units, so the limit leaves letters a wide margin and
	 * still keeps one body inside the processing budget of the feature.
	 */
	private const MAX_CSS_SCAN_VOLUME = 256 * 1024;

	// The body of a color function is read without its own brackets: `[^)]*` would let the engine try the
	// whole tail again from every opening bracket of an unclosed function, and nothing bounds that work.
	private const COLOR_VALUE_PATTERN = '/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?)\([^()]*\)/i';

	private const COLOR_ATTRIBUTES = ['bgcolor', 'color', 'text', 'link', 'vlink', 'alink', 'bordercolor'];

	public function __construct(
		private readonly DocumentLoader $documentLoader = new DocumentLoader(),
	)
	{
	}

	/**
	 * Tells whether the body may be recolored and returns the parsed document for the recolor pipeline.
	 *
	 * @param string $body
	 * @return Result Data: the parsed document.
	 *                Errors: a single rejection reason from the closed set of REASON_* codes.
	 */
	public function detect(string $body): Result
	{
		if ($body === '' || strlen($body) > self::MAX_BODY_SIZE)
		{
			return $this->reject(self::REASON_BUDGET_SIZE);
		}

		try
		{
			if ($this->countMarkupTokens($body) > self::MAX_MARKUP_TOKENS)
			{
				return $this->reject(self::REASON_BUDGET_MARKUP);
			}

			if ($this->hasUnsupportedRawStyleSheetSyntax($body))
			{
				return $this->reject(self::REASON_UNSUPPORTED_DARK_MEDIA_CSS);
			}

			$loadResult = $this->documentLoader->load($body);
			if (!$loadResult->isSuccess())
			{
				return (new Result())->addErrors($loadResult->getErrors());
			}

			$data = $loadResult->getData();
			$reason = $this->findBlockingSignal($data[self::DOCUMENT_KEY]);
			if ($reason !== null)
			{
				return $this->reject($reason);
			}

			return (new Result())->setData($data);
		}
		catch (\Throwable)
		{
			// The reason set is closed: a failure while inspecting the tree is reported as a parse failure.
			return $this->reject(self::REASON_PARSE_FAILED);
		}
	}

	/**
	 * Budget spent before the DOM exists: the node and depth limits only fire once the whole tree has been
	 * materialised. Overestimates markup in plain text on purpose - the conservative default is cheaper
	 * than a tree nobody can afford to walk.
	 */
	private function countMarkupTokens(string $body): int
	{
		Ini::adjustPcreBacktrackLimit(strlen($body) * 2);

		$count = preg_match_all('/<[a-zA-Z!\/?]/', $body);
		if ($count === false)
		{
			// A scan the engine could not finish is not the answer "no markup at all": the budget it feeds would
			// then read as untouched. The reason set is closed, and the catch of detect() has the code for a body
			// the detector could not inspect.
			throw new \RuntimeException('the markup token scan did not finish');
		}

		return $count;
	}

	private function hasUnsupportedRawStyleSheetSyntax(string $body): bool
	{
		$matchCount = preg_match_all(
			'/<style\b[^>]*>(.*?)(?:<\/style\s*>|$)/is',
			$body,
			$matches,
		);
		if ($matchCount === false)
		{
			return true;
		}

		foreach ($matches[1] as $styleSheet)
		{
			if (StyleSheetRewriter::hasUnsupportedScannerSyntax($styleSheet))
			{
				return true;
			}
		}

		return false;
	}

	private function findBlockingSignal(\DOMDocument $document): ?string
	{
		$stack = [[$document, 0]];
		$nodeCount = 0;
		$styleRuleCount = 0;
		$cssDepth = 0;
		$cssValueDepth = 0;
		$cssScanVolume = 0;
		$cssDeclarationSeparatorCount = 0;
		$colors = [];

		while ($stack !== [])
		{
			[$node, $depth] = array_pop($stack);
			$nodeCount++;

			if ($node instanceof \DOMElement)
			{
				$styleRuleCount += $this->countStyleRules($node);
				[$blockDepth, $blockVolume] = $this->measureStyleSheet($node);
				$cssDepth = max($cssDepth, $blockDepth);
				$cssValueDepth = max($cssValueDepth, $this->measureCssValueDepth($node));
				$cssScanVolume += $blockVolume;
				$cssDeclarationSeparatorCount += $this->countCssDeclarationSeparators($node);
			}

			$exhaustedBudget = $this->findExhaustedBudget(
				$nodeCount,
				$depth,
				$cssDepth,
				$cssValueDepth,
				$cssScanVolume,
				$cssDeclarationSeparatorCount,
				$styleRuleCount,
			);
			if ($exhaustedBudget !== null)
			{
				return $exhaustedBudget;
			}

			if ($node instanceof \DOMElement)
			{
				$colors += $this->extractDeclaredColors($node);
				if (count($colors) > self::MAX_UNIQUE_COLORS)
				{
					return self::REASON_BUDGET_COLORS;
				}

				if ($this->hasUnsupportedDarkMediaCss($node))
				{
					return self::REASON_UNSUPPORTED_DARK_MEDIA_CSS;
				}
			}

			for ($child = $node->lastChild; $child !== null; $child = $child->previousSibling)
			{
				$stack[] = [$child, $depth + 1];
			}
		}

		return null;
	}

	private function hasUnsupportedDarkMediaCss(\DOMElement $element): bool
	{
		return
			strtolower($element->tagName) === 'style'
			&& StyleSheetRewriter::hasUnsupportedLiftedColorCascade($element->textContent);
	}

	private function findExhaustedBudget(
		int $nodeCount,
		int $depth,
		int $cssDepth,
		int $cssValueDepth,
		int $cssScanVolume,
		int $cssDeclarationSeparatorCount,
		int $styleRuleCount,
	): ?string
	{
		if ($nodeCount > self::MAX_NODE_COUNT)
		{
			return self::REASON_BUDGET_NODES;
		}

		// Three nestings, one answer: markup the walk descends, blocks the sheet rewriter descends and
		// functions the value readers descend. Each of them is a structure nested deeper than the recolor
		// reads, which is the one thing the reason says.
		if (
			$depth > self::MAX_DEPTH
			|| $cssDepth > self::MAX_CSS_DEPTH
			|| $cssValueDepth > self::MAX_CSS_VALUE_DEPTH
		)
		{
			return self::REASON_BUDGET_DEPTH;
		}

		if ($cssScanVolume > self::MAX_CSS_SCAN_VOLUME)
		{
			return self::REASON_BUDGET_CSS_VOLUME;
		}

		if (
			$styleRuleCount > self::MAX_STYLE_RULES
			|| $cssDeclarationSeparatorCount > self::MAX_CSS_DECLARATION_SEPARATORS
		)
		{
			return self::REASON_BUDGET_RULES;
		}

		return null;
	}

	/**
	 * Declaration blocks are counted by their opening brace, so an at-rule adds one extra count. That
	 * only makes the budget stricter and does not need a CSS parser to stay honest.
	 */
	private function countStyleRules(\DOMElement $element): int
	{
		return strtolower($element->tagName) === 'style'
			? substr_count($element->textContent, '{')
			: 0;
	}

	/**
	 * Measures the nesting of a style block and the amount of text the recolor will scan inside it.
	 *
	 * The scan volume is the sum of the nesting depth over the characters of the sheet: the rewriter reads the
	 * body of a block once per level of nesting that holds it, so this is the cost of the sheet and not its size.
	 * A string is closed on a line break, the way CSS closes a bad string, so text after an unterminated quote
	 * is counted as the structure the reader sees.
	 *
	 * @return array{0: int, 1: int} Deepest nesting of the sheet and the volume of text to scan.
	 */
	private function measureStyleSheet(\DOMElement $element): array
	{
		if (strtolower($element->tagName) !== 'style')
		{
			return [0, 0];
		}

		$styleSheet = $element->textContent;
		$length = strlen($styleSheet);
		$depth = 0;
		$maximum = 0;
		$volume = 0;
		$quote = '';
		$inComment = false;

		for ($position = 0; $position < $length; $position++)
		{
			$character = $styleSheet[$position];
			$volume += $depth;

			if ($inComment)
			{
				if ($character === '*' && ($styleSheet[$position + 1] ?? '') === '/')
				{
					$inComment = false;
					$position++;
				}

				continue;
			}

			if ($quote !== '')
			{
				if ($character === '\\')
				{
					$position++;
				}
				elseif ($character === $quote || self::isLineBreak($character))
				{
					$quote = '';
				}

				continue;
			}

			if ($character === '/' && ($styleSheet[$position + 1] ?? '') === '*')
			{
				$inComment = true;
				$position++;

				continue;
			}

			if ($character === '"' || $character === "'")
			{
				$quote = $character;

				continue;
			}

			if ($character === '{')
			{
				$depth++;
				$maximum = max($maximum, $depth);

				if ($maximum > self::MAX_CSS_DEPTH)
				{
					return [$maximum, $volume];
				}
			}
			elseif ($character === '}' && $depth > 0)
			{
				$depth--;
			}
		}

		return [$maximum, $volume];
	}

	private static function isLineBreak(string $character): bool
	{
		return $character === "\n" || $character === "\r" || $character === "\f";
	}

	private function countCssDeclarationSeparators(\DOMElement $element): int
	{
		$count = substr_count($element->getAttribute('style'), ';');

		return strtolower($element->tagName) === 'style'
			? $count + substr_count($element->textContent, ';')
			: $count;
	}

	/**
	 * Returns the deepest nesting of functions in the CSS the element carries: its style attribute and, for a
	 * style block, its text.
	 *
	 * Nesting is counted per declaration - a brace, a semicolon and the end of the text all close whatever was
	 * open - so an unclosed bracket costs the declaration it stands in and not the whole sheet. Comments and
	 * strings are stepped over by the same scanner the rewriters read them with: a bracket written inside one
	 * opens nothing.
	 */
	private function measureCssValueDepth(\DOMElement $element): int
	{
		$css = $element->getAttribute('style');
		if (strtolower($element->tagName) === 'style')
		{
			$css .= ';' . $element->textContent;
		}

		if (!str_contains($css, '('))
		{
			return 0;
		}

		// Without a comment or a string there is nothing the atoms could hide, and the scan is then one pass of
		// character comparisons instead of one call per character - which is the whole cost of this budget on a
		// style attribute of an ordinary letter.
		$plain = strpbrk($css, '"\'') === false && !str_contains($css, '/*');

		$depth = 0;
		$maximum = 0;
		$length = strlen($css);

		for ($position = 0; $position < $length; $position++)
		{
			$atomLength = $plain ? 1 : CssScanner::getAtomLength($css, $position);
			if ($atomLength > 1)
			{
				$position += $atomLength - 1;

				continue;
			}

			$character = $css[$position];

			if ($character === '(')
			{
				$depth++;
				$maximum = max($maximum, $depth);
			}
			elseif ($character === ')')
			{
				$depth = max(0, $depth - 1);
			}
			elseif ($character === '{' || $character === '}' || $character === ';')
			{
				$depth = 0;
			}
		}

		return $maximum;
	}

	/**
	 * @return array<string, true> Distinct color values declared by the element, used as a budget only.
	 */
	private function extractDeclaredColors(\DOMElement $element): array
	{
		$colors = [];

		foreach (self::COLOR_ATTRIBUTES as $attributeName)
		{
			$value = strtolower(trim($element->getAttribute($attributeName)));
			if ($value !== '')
			{
				$colors[$value] = true;
			}
		}

		$styleSources = [$element->getAttribute('style')];
		if (strtolower($element->tagName) === 'style')
		{
			$styleSources[] = $element->textContent;
		}

		foreach ($styleSources as $styleSource)
		{
			$colors += $this->extractColorValues($styleSource);
		}

		return $colors;
	}

	/**
	 * @return array<string, true>
	 */
	private function extractColorValues(string $styles): array
	{
		$matchCount = preg_match_all(self::COLOR_VALUE_PATTERN, $styles, $matches);
		if ($matchCount === false)
		{
			// Same as the markup token count: a scan nobody finished must not feed the color budget as a zero.
			throw new \RuntimeException('the color value scan did not finish');
		}

		if ($matchCount === 0)
		{
			return [];
		}

		$colors = [];

		foreach ($matches[0] as $color)
		{
			$key = preg_replace('/\s+/', '', strtolower($color));
			if ($key === null)
			{
				throw new \RuntimeException('the color value normalization did not finish');
			}

			$colors[$key] = true;
		}

		return $colors;
	}

	private function reject(string $reason): Result
	{
		return (new Result())->addError(
			new Error('Message body cannot be recolored for a dark theme.', $reason),
		);
	}
}
