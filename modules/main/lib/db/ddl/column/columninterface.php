<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Column;

interface ColumnInterface
{
	/** @internal Used by Renderer to inspect column properties. */
	public function getParams(): ColumnParams;
}
