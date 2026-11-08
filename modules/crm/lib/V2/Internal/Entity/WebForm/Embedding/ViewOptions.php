<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding;

use InvalidArgumentException;

/**
 * Allowed values of the display settings of one embedding variant.
 *
 * Only the parameters that really have an enumeration are listed here; a null list means the
 * parameter does not belong to the variant.
 *
 * @internal
 */
final readonly class ViewOptions
{
	/**
	 * Names of the enumerations a setting of a variant is checked against. A write of the settings
	 * names one of these instead of reaching for a list of its own, so the values a client may send
	 * are the values the read publishes beside the settings.
	 */
	public const ENUMERATION_TYPE = 'type';
	public const ENUMERATION_POSITION = 'position';
	public const ENUMERATION_VERTICAL = 'vertical';
	public const ENUMERATION_DELAY = 'delay';
	public const ENUMERATION_BUTTON_FONT = 'buttonFont';
	public const ENUMERATION_BUTTON_ALIGN = 'buttonAlign';

	/**
	 * A copy of the list the legacy editor is built with, in
	 * {@see \Bitrix\Crm\Controller\Form::getDictForEmbed()}. The list belongs to
	 * {@see \Bitrix\Crm\WebForm\Options\Dictionary}, and until it lives there the two copies are
	 * kept in step by hand.
	 */
	private const DELAYS = [3, 5, 7, 10, 15, 20, 25, 30, 40, 60, 120];

	private const VARIANTS = [EmbeddingVariant::CLICK, EmbeddingVariant::AUTO];

	/**
	 * @param string[] $types
	 * @param string[] $positions
	 * @param string[] $verticals
	 * @param int[]|null $delays
	 * @param string[]|null $buttonFonts
	 * @param string[]|null $buttonAligns
	 */
	public function __construct(
		public array $types,
		public array $positions,
		public array $verticals,
		public ?array $delays = null,
		public ?array $buttonFonts = null,
		public ?array $buttonAligns = null,
	)
	{
	}

	/**
	 * Builds the allowed values of a variant out of the form dictionary.
	 *
	 * The dictionary knows no delays, so the variant that has them carries the list that
	 * {@see \Bitrix\Crm\Controller\Form} synthesizes for the editor.
	 *
	 * @param array<mixed> $dictionaryViews {@see \Bitrix\Crm\WebForm\Options\Dictionary::getViews()}
	 */
	public static function forVariant(string $variant, array $dictionaryViews): self
	{
		if (!in_array($variant, self::VARIANTS, true))
		{
			throw new InvalidArgumentException(sprintf('Embedding variant `%s` has no options', $variant));
		}

		$isClick = $variant === EmbeddingVariant::CLICK;
		$button = is_array($dictionaryViews['button'] ?? null) ? $dictionaryViews['button'] : [];

		return new self(
			types: self::identifiers($dictionaryViews, 'types'),
			positions: self::identifiers($dictionaryViews, 'positions'),
			verticals: self::identifiers($dictionaryViews, 'verticals'),
			delays: $isClick ? null : self::DELAYS,
			buttonFonts: $isClick ? self::identifiers($button, 'fonts') : null,
			buttonAligns: $isClick ? self::identifiers($button, 'aligns') : null,
		);
	}

	/**
	 * Values the enumeration accepts, or null when the enumeration belongs to another variant.
	 *
	 * An empty list is the portal publishing no values at all: a caller checking an input against it
	 * has nothing to check against, and refusing every value would be the dictionary of the portal
	 * closing a setting rather than the input being wrong.
	 *
	 * @return null|string[]|int[]
	 */
	public function values(string $enumeration): ?array
	{
		return match ($enumeration)
		{
			self::ENUMERATION_TYPE => $this->types,
			self::ENUMERATION_POSITION => $this->positions,
			self::ENUMERATION_VERTICAL => $this->verticals,
			self::ENUMERATION_DELAY => $this->delays,
			self::ENUMERATION_BUTTON_FONT => $this->buttonFonts,
			self::ENUMERATION_BUTTON_ALIGN => $this->buttonAligns,
			default => throw new InvalidArgumentException(
				sprintf('Embedding enumeration `%s` does not exist', $enumeration),
			),
		};
	}

	/**
	 * @param array<mixed> $dictionary
	 * @return string[]
	 */
	private static function identifiers(array $dictionary, string $key): array
	{
		$items = $dictionary[$key] ?? null;
		if (!is_array($items))
		{
			return [];
		}

		$identifiers = [];
		foreach ($items as $item)
		{
			$identifier = is_array($item) ? ($item['id'] ?? null) : null;
			if (is_string($identifier) && $identifier !== '')
			{
				$identifiers[] = $identifier;
			}
		}

		return $identifiers;
	}
}
