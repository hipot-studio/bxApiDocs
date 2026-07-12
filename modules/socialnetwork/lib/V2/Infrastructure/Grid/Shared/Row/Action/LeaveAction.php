<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action;

use Bitrix\Main\Grid\Row\Action\Action;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Js\ProjectListControllerActionBuilder;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\GridAccessServiceInterface;

class LeaveAction implements Action
{
	public function __construct(
		private readonly int $currentUserId = 0,
		private readonly ?GridAccessServiceInterface $accessService = null,
		private readonly string $text = '',
	)
	{
	}

	public static function getId(): ?string
	{
		return 'leave';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	public function getControl(array $rawFields): ?array
	{
		$id = (int)($rawFields['ID'] ?? 0);

		if (!$this->canLeave($rawFields, $id))
		{
			return null;
		}

		$href = (string)($rawFields['LEAVE_URL'] ?? '');
		if ($href === '')
		{
			$href = '/workgroups/group/' . $id . '/user_leave/';
		}

		$type = (string)($rawFields['TYPE'] ?? '');
		if (in_array($type, [Type::Project->value, Type::Collab->value], true))
		{
			return [
				'text' => $this->text,
				'onclick' => ProjectListControllerActionBuilder::buildRowActionByRoute(
					action: 'leave',
					entityId: $id,
					actionPrefix: 'socialnetwork.v2.Project.Member',
					entityParam: 'projectId',
				),
			];
		}

		return [
			'text' => $this->text,
			'href' => $href,
		];
	}

	private function canLeave(array $rawFields, int $id): bool
	{
		if (isset($rawFields['CAN_LEAVE']))
		{
			return (bool)$rawFields['CAN_LEAVE'];
		}

		return $this->accessService !== null
			&& $this->accessService->canLeave($this->currentUserId, $id);
	}
}
