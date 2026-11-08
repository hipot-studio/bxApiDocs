<?php

namespace Bitrix\CrmMobile\Dto;

use Bitrix\Mobile\Dto\Attributes\Collection;

final class Stage extends \Bitrix\Mobile\UI\Kanban\Dto\Stage
{
	public string $semantics;

	#[Collection(Tunnel::class)]
	public array $tunnels = [];

	/**
	 * The two capabilities a stage card needs of its own. Every payload of this module states them -
	 * the funnel responses and the created stage alike - so `null` stands for a box whose CRM predates
	 * the fields. It is not a refusal: a client reading it falls back to the former card gate.
	 */
	public ?bool $canManageStages = null;

	public ?bool $canManageTunnels = null;
}
