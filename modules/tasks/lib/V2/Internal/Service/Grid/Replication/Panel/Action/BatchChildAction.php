<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Panel\Action;

use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\Action\Group\GroupChildAction;
use Bitrix\Main\Grid\Panel\Snippet\Onchange;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;

/**
 * Generic panel item for template actions via `Replication.Batch.run`
 * (change members / move / delete) - P4.T3. The item id equals the batch controller action
 * name; by the item VALUE the frontend calls the right batch endpoint (native processRequest
 * is not used, hence null).
 */
final class BatchChildAction extends GroupChildAction
{
	public function __construct(
		private readonly string $id,
		private readonly string $name,
	)
	{
	}

	public static function getId(): string
	{
		// Interface requirement; unused in the client scenario - the frontend takes
		// VALUE from getDropdownItem() (native processRequest is not involved).
		return 'replication_batch_action';
	}

	public function getDropdownItem(): array
	{
		return [
			'VALUE' => $this->id,
			'NAME' => $this->name,
			'ONCHANGE' => $this->getOnchange()->toArray(),
		];
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		return null; // handled in Replication.Batch.run
	}

	protected function getOnchange(): Onchange
	{
		// The item VALUE is the batch action name; the frontend remembers it and runs it on "Apply".
		return new Onchange([
			[
				'ACTION' => Actions::CALLBACK,
				'DATA' => [
					['JS' => "BX.Tasks.V2.Replication.setGroupAction('{$this->id}')"],
				],
			],
		]);
	}
}
