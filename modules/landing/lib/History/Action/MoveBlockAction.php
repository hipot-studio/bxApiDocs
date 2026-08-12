<?php

namespace Bitrix\Landing\History\Action;

use Bitrix\Landing\Landing;
use Bitrix\Landing\Landing\BlockOrderService;

class MoveBlockAction extends BaseAction
{
	protected const JS_COMMAND = 'moveBlock';

	protected function doExecute(bool $undo = true): bool
	{
		$landing = $this->createLanding((int)$this->params['lid']);
		if (!$landing->exist())
		{
			return false;
		}

		$order = $undo ? $this->params['orderBefore'] : $this->params['orderAfter'];
		$result = $this->createBlockOrderService()->applyOrder($landing, (array)$order);

		if ($result->isSuccess())
		{
			return true;
		}

		$error = $result->getErrors()[0] ?? null;

		return $error && $error->getMessage() === BlockOrderService::ERROR_NO_POSITION_CHANGE;
	}

	public static function enrichParams(array $params): array
	{
		return [
			'lid' => (int)($params['lid'] ?? $params['landingId'] ?? 0),
			'orderBefore' => self::normalizeOrder((array)($params['orderBefore'] ?? [])),
			'orderAfter' => self::normalizeOrder((array)($params['orderAfter'] ?? [])),
			'movedIds' => self::normalizeOrder((array)($params['movedIds'] ?? [])),
		];
	}

	public function isNeedPush(): bool
	{
		return
			parent::isNeedPush()
			&& !empty($this->params['movedIds'])
			&& ($this->params['orderBefore'] ?? []) !== ($this->params['orderAfter'] ?? []);
	}

	/**
	 * @param bool $undo - if false - redo
	 * @return array
	 */
	public function getJsCommand(bool $undo = true): array
	{
		$command = parent::getJsCommand($undo);
		$command['params'] = [
			'order' => $undo ? $this->params['orderBefore'] : $this->params['orderAfter'],
			'movedIds' => $this->params['movedIds'],
		];

		return $command;
	}

	/**
	 * @param list<mixed> $order
	 * @return list<int>
	 */
	private static function normalizeOrder(array $order): array
	{
		$result = [];
		foreach ($order as $blockId)
		{
			$blockId = (int)$blockId;
			if ($blockId > 0 && !in_array($blockId, $result, true))
			{
				$result[] = $blockId;
			}
		}

		return $result;
	}

	protected function createLanding(int $landingId): Landing
	{
		return Landing::createInstance($landingId);
	}

	protected function createBlockOrderService(): BlockOrderService
	{
		return new BlockOrderService();
	}
}
