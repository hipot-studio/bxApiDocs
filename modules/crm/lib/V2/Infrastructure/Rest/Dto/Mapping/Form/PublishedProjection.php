<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoField;
use ReflectionClass;

/**
 * The published format of one document, walked by the path of an input key. The write mappings of
 * the family read their input against it: reading and writing share one format, so the DTO of the
 * read answers for the write as well and no mapping keeps a second map of what it publishes.
 *
 * The walk is the same work whatever document is being read, so it lives here once, parametrized by
 * the DTO standing at the root. A step through a collection continues on an element of it: the paths
 * here name the shape of the document and carry no index.
 *
 * One projection serves every reader of a root, and every answer of it is remembered: the answers
 * depend on nothing but the declarations of the DTO classes, while building a DTO restores its whole
 * field collection through reflection - a cost every path of every document would otherwise pay.
 */
final class PublishedProjection
{
	/** @var array<class-string<Dto>, self> projection by the DTO standing at the root */
	private static array $projections = [];

	/** @var array<class-string<Dto>, Dto> DTO of a published format by class name */
	private static array $dtos = [];

	/** @var array<string, ?DtoField> published field by path */
	private array $fields = [];

	/**
	 * @param class-string<Dto> $root
	 */
	private function __construct(private readonly string $root)
	{
	}

	/**
	 * @param class-string<Dto> $root the DTO the paths of the document start at
	 */
	public static function of(string $root): self
	{
		return self::$projections[$root] ??= new self($root);
	}

	/**
	 * The name of the document, the one a path outside it is refused by: such a path is answered as
	 * an unavailable property of this projection, the way the framework answers it.
	 */
	public function name(): string
	{
		return (new ReflectionClass($this->root))->getShortName();
	}

	/**
	 * The field the projection reads the path on, or null when the projection carries no such path.
	 */
	public function fieldAt(string $path): ?DtoField
	{
		if (array_key_exists($path, $this->fields))
		{
			return $this->fields[$path];
		}

		$dto = self::dtoOf($this->root);
		$properties = explode('.', $path);
		$lastProperty = array_key_last($properties);
		$field = null;

		foreach ($properties as $index => $property)
		{
			$field = $dto->getFields()[$property] ?? null;
			if ($field === null || $index === $lastProperty)
			{
				break;
			}

			$type = self::contentTypeOf($field);
			if ($type === null)
			{
				$field = null;

				break;
			}

			$dto = self::dtoOf($type);
		}

		return $this->fields[$path] = $field;
	}

	/**
	 * The DTO the path stands for - the element of a collection, or the object of a section.
	 *
	 * @return class-string<Dto>|null null when the projection carries no such path, and when the path
	 *         holds no object at all
	 */
	public function contentTypeAt(string $path): ?string
	{
		$field = $this->fieldAt($path);

		return $field === null ? null : self::contentTypeOf($field);
	}

	/**
	 * Properties the projection gives the object the path stands for.
	 *
	 * @return string[] empty when the path holds no object
	 */
	public function propertiesAt(string $path): array
	{
		$type = $this->contentTypeAt($path);
		if ($type === null)
		{
			return [];
		}

		$properties = [];
		foreach (self::dtoOf($type)->getFields() as $field)
		{
			$properties[] = $field->getPropertyName();
		}

		return $properties;
	}

	/**
	 * @return class-string<Dto>|null null when the field holds no object at all
	 */
	private static function contentTypeOf(DtoField $field): ?string
	{
		$type = $field->getElementType() ?? $field->getPropertyType();

		return is_subclass_of($type, Dto::class) ? $type : null;
	}

	/**
	 * @param class-string<Dto> $type
	 */
	private static function dtoOf(string $type): Dto
	{
		return self::$dtos[$type] ??= $type::create();
	}
}
