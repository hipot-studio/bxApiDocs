<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Resolvers;

use Bitrix\Anonymizer\Internal\Entities\Replacement\ReplacementDto;
use Bitrix\Anonymizer\Public\Providers\ProviderInterface;
use Bitrix\Anonymizer\Public\Providers\TextProviderInterface;

/**
 * Applies replacements to plain text by substituting segments with placeholders.
 * Replaces from end to start to preserve character offsets.
 *
 * @extends ReplaceResolver<TextProviderInterface, string>
 */
class PlainText extends ReplaceResolver
{
	public function getAnonymized(): string
	{
		$text = $this->provider->getText();

		if ($this->replacements === null)
		{
			return $text;
		}

		$this->replacements->sortByPosition(false);
		$list = $this->replacements->getReplacements();

		if ($list === [])
		{
			return $text;
		}

		foreach ($list as $dto)
		{
			$text = $this->replaceSegment($text, $dto);
		}

		return $text;
	}

	private function replaceSegment(string $text, ReplacementDto $dto): string
	{
		$start = $dto->start;
		$end = $dto->end;
		$length = $end - $start;

		if ($length <= 0 || $start < 0 || $end > mb_strlen($text))
		{
			return $text;
		}

		$replacement = $dto->getReplace();

		return mb_substr($text, 0, $start) . $replacement . mb_substr($text, $end);
	}

	/**
	 * @param TextProviderInterface $providerWithAnonymizedContent
	 */
	public function restoreDeanonymized(ProviderInterface $providerWithAnonymizedContent): string
	{
		if ($this->replacements === null)
		{
			return $providerWithAnonymizedContent->getText();
		}

		$text = $providerWithAnonymizedContent->getText();
		$list = $this->replacements->getReplacements();

		foreach ($list as $dto)
		{
			$placeholder = $dto->getReplace();
			$pos = mb_strpos($text, $placeholder);
			if ($pos !== false)
			{
				$len = mb_strlen($placeholder);
				$text = mb_substr($text, 0, $pos) . $dto->text . mb_substr($text, $pos + $len);
			}
		}

		return $text;
	}
}
