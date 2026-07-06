<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Renderer;

use Bitrix\Main\DB\Ddl\Builder\AlterTableData;
use Bitrix\Main\DB\Ddl\Builder\CreateTableData;

interface RendererInterface
{
	/**
	 * @return string[] Full list of CREATE TABLE (and possibly CREATE INDEX for pgsql) queries.
	 */
	public function renderCreateTable(CreateTableData $data): array;

	/**
	 * @return string[] Full list of ALTER TABLE / DROP INDEX / CREATE INDEX / ANALYZE queries.
	 */
	public function renderAlterTable(AlterTableData $data): array;

	/**
	 * Full DROP TABLE statement (with IF EXISTS).
	 */
	public function renderDropTable(string $tableName): string;
}
