<?php

namespace Bitrix\Landing\History\Action;

use Bitrix\Landing\Block;
use Bitrix\Landing\History\ActionParamsGuard;

class UpdateContentAction extends BaseAction
{
	protected const JS_COMMAND = 'updateContent';

	public static function getSanitizableParamKeys(): array
	{
		return ['contentBefore', 'contentAfter'];
	}

	protected function doExecute(bool $undo = true): bool
	{
		$block = new Block((int)$this->params['block']);
		if ($block->exist())
		{
			$content = $undo ? $this->params['contentBefore'] : $this->params['contentAfter'];
			$content = ActionParamsGuard::prepareHtmlForSave((string)$content);
			if ($content === null)
			{
				return false;
			}

			$block->saveContent($content, $this->params['designed']);

			return $block->save();
		}

		return false;
	}

	public static function enrichParams(array $params): array
	{
		$checked = ActionParamsGuard::rejectUnsafeValueParams(
			[
				'contentAfter' => $params['contentAfter'] ?: '',
				'contentBefore' => $params['contentBefore'] ?: '',
			],
			static::getSanitizableParamKeys(),
			static::class,
		);

		return [
			'block' => $params['block'],
			'contentAfter' => $checked['contentAfter'],
			'contentBefore' => $checked['contentBefore'],
			'designed' => (bool)$params['designed'],
		];
	}

	public function isNeedPush(): bool
	{
		return
			parent::isNeedPush()
			&& $this->params['contentBefore'] !== $this->params['contentAfter'];
	}

	/**
	 * @param bool $undo - if false - redo
	 * @return array
	 */
	public function getJsCommand(bool $undo = true): array
	{
		$params = parent::getJsCommand($undo);

		$params['params']['content'] =
			$undo
				? $params['params']['contentBefore']
				: $params['params']['contentAfter']
		;

		unset(
			$params['params']['contentAfter'],
			$params['params']['contentBefore'],
			$params['params']['designed'],
		);

		return $params;
	}
}
