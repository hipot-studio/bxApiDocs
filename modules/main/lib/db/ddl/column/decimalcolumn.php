<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Column;

class DecimalColumn extends AbstractFloatColumn
{
	// DECIMAL(M,D) is fixed-point: precision and scale are mandatory
	public function __construct(string $name, int $mLength, int $eLength)
	{
		parent::__construct($name, $mLength, $eLength);
	}
}
