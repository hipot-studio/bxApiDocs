<?php

namespace Bitrix\Landing\History\Action;

use Bitrix\Landing\Block;
use Bitrix\Landing\History\ActionParamsGuard;
use Bitrix\Main\Text\Emoji;

class EditTextAction extends BaseAction
{
	protected const JS_COMMAND = 'editText';

	public static function getSanitizableParamKeys(): array
	{
		return ['valueBefore', 'valueAfter'];
	}

	public static function normalizeSanitizableParam(string $key, mixed $value): mixed
	{
		if (is_string($value) && str_starts_with($key, 'value'))
		{
			return Emoji::decode($value);
		}

		return $value;
	}

	protected function doExecute(bool $undo = true): bool
	{
		$block = new Block((int)$this->params['block']);
		$selector = $this->params['selector'] ?: '';
		$position = (int)($this->params['position'] ?: 0);

		if ($selector)
		{
			$doc = $block->getDom();
			$resultList = $doc->querySelectorAll($selector);
			if (isset($resultList[$position]))
			{
				$content = $undo ? $this->params['valueBefore'] : $this->params['valueAfter'];
				$content = Emoji::decode($content);
				$content = ActionParamsGuard::prepareHtmlForSave($content);
				if ($content === null)
				{
					return false;
				}
				$resultList[$position]->setInnerHTML($content);
				$block->saveContent($doc->saveHTML());

				return $block->save();
			}
		}

		return false;
	}

	public static function enrichParams(array $params): array
	{
		/**
		 * @var $block Block
		 */
		$block = $params['block'];

		$valueBefore = $params['valueBefore'] ?: '';
		$valueAfter = $params['valueAfter'] ?: '';

		$checked = ActionParamsGuard::rejectUnsafeValueParams(
			[
				'valueBefore' => $valueBefore,
				'valueAfter' => $valueAfter,
			],
			static::getSanitizableParamKeys(),
			static::class,
		);
		$valueBefore = $checked['valueBefore'];
		$valueAfter = $checked['valueAfter'];

		$valueBefore = Emoji::encode($valueBefore);
		$valueAfter = Emoji::encode($valueAfter);

		return [
			'block' => $block->getId(),
			'selector' => $params['selector'] ?: '',
			'position' => $params['position'] ?: 0,
			'lid' => $block->getLandingId(),
			'valueAfter' => $valueAfter,
			'valueBefore' => $valueBefore,
		];
	}

	/**
	 * @param bool $undo - if false - redo
	 * @return array
	 */
	public function getJsCommand(bool $undo = true): array
	{
		$params = parent::getJsCommand($undo);

		$params['params']['selector'] .= '@' . $params['params']['position'];
		$params['params']['value'] =
			$undo
				? $params['params']['valueBefore']
				: $params['params']['valueAfter']
			;
		$params['params']['value'] = Emoji::decode($params['params']['value']);

		unset(
			$params['params']['valueAfter'],
			$params['params']['valueBefore'],
			$params['params']['position'],
		);

		return $params;
	}

	/**
	 * Check if params duplicated with previously step
	 * @param array $oldParams
	 * @param array $newParams
	 * @return bool
	 */
	public static function compareParams(array $oldParams, array $newParams): bool
	{
		unset($oldParams['valueBefore'], $newParams['valueBefore']);

		return $oldParams === $newParams;
	}
}
