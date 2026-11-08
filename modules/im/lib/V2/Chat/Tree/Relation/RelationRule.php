<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree\Relation;

/**
 * Structural rule of a parent chat type in one of three forms: no children at all, only the listed
 * children, any child except the listed ones. Children are listed by the filter key of their
 * registered type, never by type instance.
 */
final readonly class RelationRule
{
	private const FORM_NO_CHILDREN = 'NO_CHILDREN';
	private const FORM_ALLOWED_LIST = 'ALLOWED_LIST';
	private const FORM_DISALLOWED_LIST = 'DISALLOWED_LIST';

	/**
	 * @param string[] $childKeys
	 */
	private function __construct(
		private string $form,
		private array $childKeys,
	) {}

	public static function forbidChildren(): self
	{
		return new self(self::FORM_NO_CHILDREN, []);
	}

	public static function allowOnly(string ...$childKeys): self
	{
		return new self(self::FORM_ALLOWED_LIST, $childKeys);
	}

	public static function allowAnyExcept(string ...$childKeys): self
	{
		return new self(self::FORM_DISALLOWED_LIST, $childKeys);
	}

	public function allowsChild(string $childKey): bool
	{
		return match ($this->form)
		{
			self::FORM_NO_CHILDREN => false,
			self::FORM_ALLOWED_LIST => in_array($childKey, $this->childKeys, true),
			self::FORM_DISALLOWED_LIST => !in_array($childKey, $this->childKeys, true),
		};
	}
}
