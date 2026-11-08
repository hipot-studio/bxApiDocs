<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding;

use InvalidArgumentException;

/**
 * Effective display settings of one embedding variant.
 *
 * The button block is kept flat: `buttonUsed` .. `buttonBackgroundHoverColor` describe the single
 * button of the `click` variant.
 *
 * @internal
 */
final readonly class ViewSettings
{
	/**
	 * The `FORM_SETTINGS.VIEWS` block of a form nobody has placed anywhere yet, in the form the
	 * column holds it. It is both what the read answers while the block is empty and what a write
	 * of one setting lays under itself, so that reading a form and writing it back changes nothing.
	 */
	public const DEFAULT_VIEWS = [
		EmbeddingVariant::CLICK => [
			'type' => 'panel',
			'position' => 'right',
			'vertical' => 'bottom',
		],
		EmbeddingVariant::AUTO => [
			'type' => 'popup',
			'position' => 'center',
			'vertical' => 'bottom',
			'delay' => 5,
		],
	];

	public function __construct(
		public ?string $type = null,
		public ?string $position = null,
		public ?string $vertical = null,
		public ?int $delay = null,
		public bool $buttonUsed = false,
		public ?string $buttonText = null,
		public ?string $buttonFont = null,
		public ?string $buttonAlign = null,
		public bool $buttonPlain = false,
		public bool $buttonRounded = false,
		public bool $buttonOutlined = false,
		public ?string $buttonDecoration = null,
		public ?string $buttonTextColor = null,
		public ?string $buttonTextHoverColor = null,
		public ?string $buttonBackgroundColor = null,
		public ?string $buttonBackgroundHoverColor = null,
	)
	{
	}

	/**
	 * Builds the settings of a variant out of the stored `FORM_SETTINGS.VIEWS` block.
	 *
	 * An empty stored block is replaced by the defaults of the whole tree, the same way
	 * {@see \Bitrix\Crm\WebForm\Form::loadOnlyForm()} does it.
	 *
	 * @param array<mixed> $storedViews
	 */
	public static function fromStoredViews(array $storedViews, string $variant): self
	{
		if (!isset(self::DEFAULT_VIEWS[$variant]))
		{
			throw new InvalidArgumentException(sprintf('Embedding variant `%s` has no settings', $variant));
		}

		$views = $storedViews === [] ? self::DEFAULT_VIEWS : $storedViews;
		$values = self::subArray($views, $variant);
		$button = self::subArray($values, 'button');
		$color = self::subArray($button, 'color');

		return new self(
			type: self::text($values, 'type'),
			position: self::text($values, 'position'),
			vertical: self::text($values, 'vertical'),
			delay: self::number($values, 'delay'),
			buttonUsed: self::flag($button, 'use'),
			buttonText: self::text($button, 'text'),
			buttonFont: self::text($button, 'font'),
			buttonAlign: self::text($button, 'align'),
			buttonPlain: self::flag($button, 'plain'),
			buttonRounded: self::flag($button, 'rounded'),
			buttonOutlined: self::flag($button, 'outlined'),
			buttonDecoration: self::text($button, 'decoration'),
			buttonTextColor: self::text($color, 'text'),
			buttonTextHoverColor: self::text($color, 'textHover'),
			buttonBackgroundColor: self::text($color, 'background'),
			buttonBackgroundHoverColor: self::text($color, 'backgroundHover'),
		);
	}

	/**
	 * @param array<mixed> $values
	 * @return array<mixed>
	 */
	private static function subArray(array $values, string $key): array
	{
		$value = $values[$key] ?? null;

		return is_array($value) ? $value : [];
	}

	/**
	 * @param array<mixed> $values
	 */
	private static function text(array $values, string $key): ?string
	{
		$value = $values[$key] ?? null;
		if (is_int($value))
		{
			return (string)$value;
		}

		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * @param array<mixed> $values
	 */
	private static function number(array $values, string $key): ?int
	{
		$value = $values[$key] ?? null;

		return is_int($value) || (is_string($value) && $value !== '' && is_numeric($value))
			? (int)$value
			: null
		;
	}

	/**
	 * The storage keeps the flags as `1` and `0`, and the embed bundle reads them the same way.
	 *
	 * @param array<mixed> $values
	 */
	private static function flag(array $values, string $key): bool
	{
		$value = $values[$key] ?? null;

		return $value === true || $value === 1 || $value === '1';
	}
}
