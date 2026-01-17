<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Template\ReplicateParamsDto;
use Bitrix\Tasks\V2\Internal\Entity\Template;

class TemplateDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id = null;
	public ?TaskDto $task = null;
	public ?string $title = null;
	public ?string $description = null;
	public ?UserDto $creator = null;
	/** @var UserDto[]|null */
	public ?array $responsibleCollection = null;
	public ?int $deadlineAfterTs = null;
	public ?int $startDatePlanTs = null;
	public ?int $endDatePlanTs = null;
	public ?bool $replicate = null;
	public ?array $fileIds = null;
	public ?array $checklist = null;
	public ?GroupDto $group = null;
	public ?string $priority = null;
	/** @var UserDto[]|null */
	public ?array $accomplices = null;
	/** @var UserDto[]|null */
	public ?array $auditors = null;
	public ?self $parent = null;
	public ?ReplicateParamsDto $replicateParams = null;

	public static function fromEntity(?Template $template, ?Request $request = null): ?self
	{
		if (!$template)
		{
			return null;
		}
		$select = $request?->select?->getList(true) ?? [];
		$dto = new self();
		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $template->id;
		}
		if (empty($select) || in_array('task', $select, true))
		{
			$dto->task = $template->task ? TaskDto::fromEntity($template->task, $request) : null;
		}
		if (empty($select) || in_array('title', $select, true))
		{
			$dto->title = $template->title;
		}
		if (empty($select) || in_array('description', $select, true))
		{
			$dto->description = $template->description;
		}
		if (empty($select) || in_array('creator', $select, true))
		{
			$dto->creator = $template->creator ? UserDto::fromEntity($template->creator, $request) : null;
		}
		if (empty($select) || in_array('responsibleCollection', $select, true))
		{
			$dto->responsibleCollection = $template->responsibleCollection
				? array_map(fn($user) => UserDto::fromEntity($user, $request), $template->responsibleCollection->getAll())
				: null;
		}
		if (empty($select) || in_array('deadlineAfterTs', $select, true))
		{
			$dto->deadlineAfterTs = $template->deadlineAfterTs;
		}
		if (empty($select) || in_array('startDatePlanTs', $select, true))
		{
			$dto->startDatePlanTs = $template->startDatePlanTs;
		}
		if (empty($select) || in_array('endDatePlanTs', $select, true))
		{
			$dto->endDatePlanTs = $template->endDatePlanTs;
		}
		if (empty($select) || in_array('replicate', $select, true))
		{
			$dto->replicate = $template->replicate;
		}
		if (empty($select) || in_array('fileIds', $select, true))
		{
			$dto->fileIds = $template->fileIds;
		}
		if (empty($select) || in_array('checklist', $select, true))
		{
			$dto->checklist = $template->checklist;
		}
		if (empty($select) || in_array('group', $select, true))
		{
			$dto->group = $template->group ? GroupDto::fromEntity($template->group, $request) : null;
		}
		if (empty($select) || in_array('priority', $select, true))
		{
			$dto->priority = $template->priority?->value;
		}
		if (empty($select) || in_array('accomplices', $select, true))
		{
			$dto->accomplices = $template->accomplices
				? array_map(fn($user) => UserDto::fromEntity($user, $request), $template->accomplices->getAll())
				: null;
		}
		if (empty($select) || in_array('auditors', $select, true))
		{
			$dto->auditors = $template->auditors
				? array_map(fn($user) => UserDto::fromEntity($user, $request), $template->auditors->getAll())
				: null;
		}
		if (empty($select) || in_array('parent', $select, true))
		{
			$dto->parent = $template->parent ? self::fromEntity($template->parent, $request) : null;
		}
		if (empty($select) || in_array('replicateParams', $select, true))
		{
			$dto->replicateParams = $template->replicateParams ? ReplicateParamsDto::fromEntity($template->replicateParams, $request) : null;
		}

		return $dto;
	}
}
