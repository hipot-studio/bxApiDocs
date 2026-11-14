<?php

namespace Bitrix\VisualEditor\Public\Adapter;

use Bitrix\Main\Result;

interface EditorAdapterInterface
{
	/**
	 * Lists documents after checking the context and permissions.
	 *
	 * @param array $context
	 * @return Result
	 */
	public function list(array $context): Result;

	/**
	 * Reads document bytes after checking the context and permissions.
	 *
	 * @param array $context
	 * @param string $path
	 * @return Result
	 */
	public function read(array $context, string $path): Result;

	/**
	 * Saves document bytes using the consumer's concurrency and permission checks.
	 *
	 * @param array $context
	 * @param array $changeSet
	 * @return Result
	 */
	public function save(array $context, array $changeSet): Result;
}
