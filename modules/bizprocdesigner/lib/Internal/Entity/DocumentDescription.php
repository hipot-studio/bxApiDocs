<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

class DocumentDescription implements Arrayable
{
	/** What joins the three parts in the canonical spelling of a document type. */
	public const CANONICAL_SEPARATOR = '@';

	private const CANONICAL_PART_COUNT = 3;

	public function __construct(
		public readonly string $module,
		public readonly string $entityType,
		public readonly string $documentType,
	) {}

	/**
	 * The type a caller wrote as `module@entity@documentType`, or null when the string is not that.
	 *
	 * Read without a single guess: the string is split on the separator, all three parts must be there,
	 * none of them empty and none of them padded with whitespace, and the type built from them must
	 * serialize back to the very characters that came in. A value that survives all four is a value the
	 * server itself would have written.
	 *
	 * The short spelling is deliberately not read here: nothing in the code base resolves an entity from a
	 * document type alone, so guessing one would authorize a type the caller never named.
	 */
	public static function fromCanonicalString(string $value): ?self
	{
		$parts = explode(self::CANONICAL_SEPARATOR, $value);
		if (count($parts) !== self::CANONICAL_PART_COUNT)
		{
			return null;
		}

		foreach ($parts as $part)
		{
			if ($part === '' || trim($part) !== $part)
			{
				return null;
			}
		}

		$description = new self($parts[0], $parts[1], $parts[2]);

		return $description->toCanonicalString() === $value ? $description : null;
	}

	/** The spelling {@see self::fromCanonicalString()} reads, and the key a type is compared by. */
	public function toCanonicalString(): string
	{
		return implode(self::CANONICAL_SEPARATOR, $this->toBizprocComplexType());
	}

	public function equals(self $other): bool
	{
		return $this->toBizprocComplexType() === $other->toBizprocComplexType();
	}

	public function toArray(): array
	{
		return [
			'module' => $this->module,
			'entityType' => $this->entityType,
			'documentType' => $this->documentType,
		];
	}

	/**
	 * @return list<string> ['module', 'entityType', 'documentType']
	 */
	public function toBizprocComplexType(): array
	{
		return [$this->module, $this->entityType, $this->documentType];
	}
}