<?php

namespace Bitrix\Rest\V3\Dto\Meta;

use Bitrix\Rest\V3\Attribute\AbstractAttribute;
use Bitrix\Rest\V3\Dto\Attribute\TypeAlias;

/**
 * Process-wide data shared by all instances of a DTO class: short name, class attributes, the parts
 * of the type name that do not depend on getShortName() and public non-static properties.
 * Built from reflection only.
 *
 * @internal
 */
final class ClassMeta
{
	/** @var array<class-string, self> */
	private static array $instances = [];

	public readonly string $shortName;

	/** @var array<class-string, AbstractAttribute> */
	public readonly array $attributes;

	/**
	 * The first two namespace segments in lower case that start the type name, null for a class
	 * without a namespace.
	 */
	public readonly ?string $typeNamePrefix;

	/**
	 * The alias from #[TypeAlias] that replaces the short name in the type name.
	 */
	public readonly ?string $typeAlias;

	/** @var array<string, \ReflectionProperty> */
	private readonly array $properties;

	private function __construct(\ReflectionClass $reflection)
	{
		$attributes = [];
		foreach ($reflection->getAttributes() as $attribute)
		{
			$attributes[$attribute->getName()] = $attribute->newInstance();
		}
		$this->attributes = $attributes;
		$this->shortName = $reflection->getShortName();

		$namespaceParts = array_filter(
			explode('\\', $reflection->getNamespaceName()),
			static fn(string $part): bool => $part !== '',
		);
		$this->typeNamePrefix = empty($namespaceParts)
			? null
			: implode('.', array_map('strtolower', array_slice($namespaceParts, 0, 2)));
		$this->typeAlias = isset($attributes[TypeAlias::class]) ? $attributes[TypeAlias::class]->alias : null;

		$properties = [];
		foreach ($reflection->getProperties() as $property)
		{
			if ($property->isPublic() && !$property->isStatic())
			{
				$properties[$property->getName()] = $property;
			}
		}
		$this->properties = $properties;
	}

	/**
	 * @param class-string $class
	 */
	public static function of(string $class): self
	{
		if (!isset(self::$instances[$class]))
		{
			// a failed build throws here and leaves nothing behind, so the next call builds again
			self::$instances[$class] = new self(new \ReflectionClass($class));
		}

		return self::$instances[$class];
	}

	public function getProperty(string $name): ?\ReflectionProperty
	{
		return $this->properties[$name] ?? null;
	}
}
