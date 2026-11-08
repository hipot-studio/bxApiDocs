<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Exception;

class SourceUnavailableException extends \Exception
{
	public const ERROR_CODE = 'ERR-004';

	public const KIND_PROVIDER = 'provider';
	public const KIND_TYPE = 'type';
	public const KIND_FIELD = 'field';
	public const KIND_DATE_WINDOW = 'date_window';

	public function __construct(string $message = '', private readonly string $kind = self::KIND_PROVIDER)
	{
		parent::__construct($message);
	}

	public function getKind(): string
	{
		return $this->kind;
	}

	public static function sourceTypeRemoved(int $storageTypeId): self
	{
		return new self(
			sprintf('DataView source storage type %d is unavailable', $storageTypeId),
			self::KIND_TYPE,
		);
	}

	public static function sourceFieldRemoved(string $fieldCode): self
	{
		return new self(
			sprintf('DataView source field "%s" is unavailable', $fieldCode),
			self::KIND_FIELD,
		);
	}

	public static function sourceVariableRemoved(string $entity, string $code): self
	{
		return new self(
			sprintf('DataView source variable "%s" of kind "%s" is unavailable', $code, $entity),
			self::KIND_TYPE,
		);
	}

	public static function sourceVariableTypeUnsupported(string $entity, string $code, string $type): self
	{
		return new self(
			sprintf(
				'DataView source variable "%s" of kind "%s" has an unsupported type "%s"',
				$code,
				$entity,
				$type,
			),
			self::KIND_TYPE,
		);
	}

	public static function stampConstantNotScalar(string $entity, string $code): self
	{
		return new self(
			sprintf(
				'DataView stamp constant "%s" of kind "%s" is multiple and carries no single value',
				$code,
				$entity,
			),
			self::KIND_TYPE,
		);
	}

	public static function sourceNotAvailableToActor(string $module, string $entity): self
	{
		return new self(
			sprintf('DataView source "%s.%s" is not available to the current actor', $module, $entity),
			self::KIND_PROVIDER,
		);
	}

	public static function sourceEntityUnknown(string $entity): self
	{
		return new self(
			sprintf('DataView source entity "%s" is not served by any native provider', $entity),
			self::KIND_PROVIDER,
		);
	}

	public static function sourceDateWindowUnsupported(string $entity): self
	{
		return new self(
			sprintf(
				'DataView source "%s" cannot apply the requested period window: no record creation date field',
				$entity,
			),
			self::KIND_DATE_WINDOW,
		);
	}
}
