<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action;

use Bitrix\Main\Grid\Row\Action\Action;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Js\ProjectListControllerActionBuilder;

class ViewAction implements Action
{
	public function __construct(
		private readonly string $text = '',
		private readonly string $urlSuffix = '',
	)
	{
	}

	public static function getId(): ?string
	{
		return 'view';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	public function getControl(array $rawFields): ?array
	{
		$id = (int)($rawFields['ID'] ?? 0);
		if ($id <= 0)
		{
			return null;
		}

		$href = (string)($rawFields['VIEW_URL'] ?? '');
		if ($href === '')
		{
			$href = '/workgroups/group/' . $id . '/' . $this->urlSuffix;
		}

		$control = [
			'text' => $this->text,
			'href' => $href,
			'default' => true,
		];

		$entityType = $rawFields['TYPE'] ?? null;
		$call = ProjectListControllerActionBuilder::buildNotifyOpenProjectAction(
			entityId: $id,
			entityType: is_string($entityType) ? $entityType : null,
		);
		if ($call !== '')
		{
			$control['onclick'] = $call;
		}

		return $control;
	}
}
