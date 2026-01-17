<?php

namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\ArgumentException;
use Bitrix\Rest\V3\Dto\DtoField;

class GeneratedDto implements \Serializable
{
	public function __construct(public readonly string $className, public readonly string $namespace, public readonly array $fields)
	{
		if (!preg_match('/^[A-Za-z_]\w*$/', $this->className)) {
			throw new ArgumentException(sprintf(
				'Invalid class name "%s". Allowed pattern: /^[A-Za-z_]\w*$/',
				$this->className
			));
		}
	}

	public function getFQCN(): string
	{
		return $this->namespace . '\\' . $this->className;
	}

	public function serialize(): ?string
	{
		return serialize($this->__serialize());
	}

	public function unserialize(string $data): void
	{
		$this->__unserialize(unserialize($data, ['allowed_classes' => false]));
	}

	public function __serialize(): array
	{
		return [
			'className' => $this->className,
			'namespace' => $this->namespace,
			'fields' => array_map(fn($field) => $field->toArray(), $this->fields),
		];
	}

	public function __unserialize(array $data): void
	{
		$this->className = $data['className'];
		$this->namespace = $data['namespace'];
		$fields = [];
		foreach ($data['fields'] as $field)
		{
			 $fields[] = DtoField::fromArray($field);
		}
		$this->fields = $fields;
	}
}
