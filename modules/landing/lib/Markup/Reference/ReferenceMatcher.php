<?php

namespace Bitrix\Landing\Markup\Reference;

/**
 * Reference matcher of a markup package: the single place that knows in which forms a package
 * path and an image token may appear in the text of the package.
 *
 * Textual by design: the same forms work in html attributes, css url() and js strings. The
 * intake substitutes exactly what this matcher finds, so a path built at runtime is neither
 * substituted nor recognized as a reference - such a file is an orphan for the validation.
 */
class ReferenceMatcher
{
	public const TOKEN_SCHEME = 'bx-asset://';

	private const TOKEN_PATTERN = '#bx-asset://([a-z0-9][a-z0-9_-]{0,63})#';

	/**
	 * Substitutes image tokens and package paths with real urls. A token is matched whole,
	 * so a listed token never touches a longer one it is a prefix of.
	 * @param array<string, string> $urlByPath
	 * @param array<string, string> $urlByToken
	 */
	public function replace(string $text, array $urlByPath, array $urlByToken): string
	{
		$text = $this->replaceTokens($text, $urlByToken);

		foreach ($this->sortByLengthDesc(array_keys($urlByPath)) as $path)
		{
			$text = str_replace(
				$this->pathForms($path),
				$this->urlForms($urlByPath[$path]),
				$text
			);
		}

		return $text;
	}

	/**
	 * Substitutes the listed image tokens only; a token is matched whole, so a listed token
	 * never touches a longer one it is a prefix of.
	 * @param array<string, string> $replacementByToken Replacement by token without the scheme.
	 */
	public function replaceTokens(string $text, array $replacementByToken): string
	{
		if (!$replacementByToken)
		{
			return $text;
		}

		return preg_replace_callback(
			self::TOKEN_PATTERN,
			static fn(array $match): string => $replacementByToken[$match[1]] ?? $match[0],
			$text
		) ?? $text;
	}

	/**
	 * @param iterable<string> $texts
	 */
	public function isPathReferenced(string $path, iterable $texts): bool
	{
		$forms = $this->pathForms($path);
		foreach ($texts as $text)
		{
			foreach ($forms as $form)
			{
				if (str_contains($text, $form))
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Image tokens used by the texts, without the scheme, in order of appearance.
	 * @param iterable<string> $texts
	 * @return array<int, string>
	 */
	public function collectTokens(iterable $texts): array
	{
		$tokens = [];
		foreach ($texts as $text)
		{
			if (preg_match_all(self::TOKEN_PATTERN, $text, $matches))
			{
				foreach ($matches[1] as $token)
				{
					$tokens[$token] = true;
				}
			}
		}

		return array_keys($tokens);
	}

	/**
	 * @param array<int, string> $keys
	 * @return array<int, string>
	 */
	private function sortByLengthDesc(array $keys): array
	{
		usort($keys, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

		return $keys;
	}

	/**
	 * @return array<int, string>
	 */
	private function pathForms(string $path): array
	{
		return [
			'"' . $path . '"',
			"'" . $path . "'",
			'(' . $path . ')',
			'"./' . $path . '"',
			"'./" . $path . "'",
			'(./' . $path . ')',
		];
	}

	/**
	 * Replacements of pathForms(), form by form.
	 * @return array<int, string>
	 */
	private function urlForms(string $url): array
	{
		return [
			'"' . $url . '"',
			"'" . $url . "'",
			'(' . $url . ')',
			'"' . $url . '"',
			"'" . $url . "'",
			'(' . $url . ')',
		];
	}
}
