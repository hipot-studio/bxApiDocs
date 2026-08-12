<?php
namespace Bitrix\Landing\History\Action;

use Bitrix\Landing\History\ActionParamsGuard;

abstract class BaseAction
{
	protected const JS_COMMAND = '';
	protected array $params = [];

	/**
	 * @param array $params
	 * @param bool $prepared - If true - no need prepare before set. Default - need prepare
	 * @return void
	 */
	public function setParams(array $params, bool $prepared = false): void
	{
		if (!$prepared)
		{
			$params = static::enrichParams($params);
		}
		$this->params = $params;
	}

	public function getParams(): array
	{
		return $this->params;
	}

	/**
	 * Security gate → domain apply. Final: no step may reach its sink bypassing the gate.
	 * (1) universal raw-`<?` marker over the whole params tree (covers non-declaring actions
	 * and MULTIPLY children); (2) XSS audit of declared HTML keys, mirroring History::push.
	 */
	final public function execute(bool $undo = true): bool
	{
		if (ActionParamsGuard::containsUnneutralizedPhpOpenTagDeep($this->params))
		{
			return false;
		}

		$sanitizableParamKeys = static::getSanitizableParamKeys();
		if (
			$sanitizableParamKeys !== []
			&& !ActionParamsGuard::validateParams($this->params, $sanitizableParamKeys, static::class)
		)
		{
			return false;
		}

		return $this->doExecute($undo);
	}

	abstract protected function doExecute(bool $undo = true): bool;
	abstract public static function enrichParams(array $params): array;

	/**
	 * Param keys that may carry user HTML and must pass ActionParamsGuard.
	 *
	 * @return list<string>
	 */
	public static function getSanitizableParamKeys(): array
	{
		return [];
	}

	/**
	 * Normalize param value before sanitization check (e.g. emoji decode in stored params).
	 */
	public static function normalizeSanitizableParam(string $key, mixed $value): mixed
	{
		return $value;
	}

	/**
	 * If need - do preliminary operations before del from table
	 * @return bool
	 */
	public function delete(): bool
	{
		return true;
	}

	/**
	 * Check correctly params before push
	 * @return bool
	 */
	public function isNeedPush(): bool
	{
		// todo: compare valuebefore||valueafter (see examples in some actions)
		return !empty($this->params);
	}

	/**
	 * @param bool $undo - if false - redo
	 * @return array
	 */
	public function getJsCommand(bool $undo = true): array
	{
		return [
			'command' => static::JS_COMMAND,
			'params' => $this->params,
		];
	}

	/**
	 * Get name of JS action command
	 * @return string
	 */
	public static function getJsCommandName(): string
	{
		return static::JS_COMMAND;
	}

	/**
	 * Check if params duplicated with previously step
	 * @param array $oldParams
	 * @param array $newParams
	 * @return bool
	 */
	public static function compareParams(array $oldParams, array $newParams): bool
	{
		return $oldParams === $newParams;
	}
}