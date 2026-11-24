<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Degradation;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Type;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer\FooterElement;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlocksBuilder;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

/**
 * Appends link-button addresses to text sent to an external open-line channel.
 *
 * Only a link button leaves a trace - it is an address and stays reachable as text. A button that
 * asks the server for something means nothing without the footer that carries it, so it goes without
 * a trace. A button addressed to named users leaves no trace either: the whole line reads the
 * channel. See FooterElement::isAllowedFor().
 *
 * The stored block and message text stay unchanged.
 */
class FooterDegradationService
{
	/**
	 * Addresses of the footers of a message for a channel that carries text only - the external
	 * channel of an open line. The order of the footer is kept: top to bottom, left to right.
	 */
	public function appendLinkUrlsToText(string $text, ?BlocksBuilder $builder): string
	{
		$urls = $this->collectLinkUrls($builder);

		if (empty($urls))
		{
			return $text;
		}

		return $this->appendUrls($text, $urls);
	}

	/**
	 * @return string[]
	 */
	public function collectLinkUrls(?BlocksBuilder $builder): array
	{
		$urls = [];

		foreach ($builder?->jsonSerialize()['elements'] ?? [] as $block)
		{
			$urls[] = $this->collectUrlsOfBlock($block);
		}

		return array_merge([], ...$urls);
	}

	/**
	 * @return string[]
	 */
	private function collectUrlsOfBlock(array $block): array
	{
		$footer = $block[Field::Footer->value] ?? [];
		if (!is_array($footer))
		{
			return [];
		}

		$urls = [];
		foreach ($footer as $row)
		{
			foreach ((is_array($row) ? $row : []) as $element)
			{
				if (!is_array($element) || FooterElement::isAddressedToSomeone($element))
				{
					continue;
				}

				$url = $element['url'] ?? null;
				if (($element['type'] ?? null) === Type::LinkButton->value && is_string($url) && $url !== '')
				{
					$urls[] = $url;
				}
			}
		}

		return $urls;
	}

	/**
	 * @param string[] $urls
	 */
	private function appendUrls(string $text, array $urls): string
	{
		return $text . "\n" . implode("\n", $urls);
	}
}
