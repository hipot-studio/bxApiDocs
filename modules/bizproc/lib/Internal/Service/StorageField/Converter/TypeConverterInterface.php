<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StorageField\Converter;

interface TypeConverterInterface
{
	public static function getType(): string;
	public function toStorage(mixed $value): mixed;
	public function fromStorage(mixed $value): mixed;
}
