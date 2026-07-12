<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Panel\Action;

use Closure;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Panel\Action\Action;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\Snippet\Onchange;
use Bitrix\Main\Grid\Panel\Types;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\GridAccessServiceInterface;

class AddToArchiveAction implements Action
{
	/**
	 * @param Closure(int): Result $archiveHandler fn(int $entityId): Result
	 */
	public function __construct(
		private readonly GridAccessServiceInterface $accessService,
		private readonly Closure $archiveHandler,
		private readonly string $text = '',
		private readonly string $confirmText = '',
		private readonly string $gridId = '',
	)
	{
	}

	public static function getId(): string
	{
		return 'addToArchive';
	}

	public function getControl(): ?array
	{
		$onchange = new Onchange([
			[
				'ACTION' => Actions::CALLBACK,
				'DATA' => [
					['JS' => $this->buildCallbackJs()],
				],
			],
		]);

		return [
			'TYPE' => Types::BUTTON,
			'ID' => static::getId(),
			'TEXT' => $this->text,
			'ONCHANGE' => $onchange->toArray(),
		];
	}

	private function buildCallbackJs(): string
	{
		return sprintf(
			'BX.Socialnetwork.Project.List.Controller.runTopAction(%s, %s, %s)',
			Json::encode($this->gridId),
			Json::encode(static::getId()),
			Json::encode([
				'CONFIRM' => true,
				'CONFIRM_APPLY_BUTTON' => $this->confirmText,
			]),
		);
	}

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		$result = new Result();

		$ids = $request->getPost('rows');
		if (!is_array($ids) || empty($ids))
		{
			return $result;
		}

		$userId = (int)(CurrentUser::get()->getId());

		foreach ($ids as $id)
		{
			$entityId = (int)$id;

			if (!$this->accessService->canUpdate($userId, $entityId))
			{
				continue;
			}

			$commandResult = ($this->archiveHandler)($entityId);
			if (!$commandResult->isSuccess())
			{
				$result->addErrors($commandResult->getErrors());
			}
		}

		return $result;
	}
}
