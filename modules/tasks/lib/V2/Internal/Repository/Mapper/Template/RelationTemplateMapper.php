<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

class RelationTemplateMapper
{
	public function __construct(
		private readonly TypeMapper $typeMapper,
	)
	{

	}

	public function mapToEntity(
		array $template,
		?UserCollection $responsibles = null,
		?array $rights = null,
		?array $subTemplateIds = null,
	): Template
	{
		$fields = [
			'id' => $template['ID'] ?? null,
			'title' => $template['TITLE'] ?? null,
			'responsibleCollection' => $responsibles,
			'deadlineAfter' => $template['DEADLINE_AFTER'] ?? null,
			'matchesWorkTime' => ($template['MATCH_WORK_TIME'] ?? null) === 'Y',
			'rights' => $rights,
			'type' => $this->typeMapper->mapToEnum((int)$template['TPARAM_TYPE']),
			'subTemplateIds' => $subTemplateIds,
		];

		return Template::mapFromArray($fields);
	}

	public function mapToCollection(
		array $templates,
		?UserCollection $users = null,
		?array $rights = null,
		?array $subTemplateIds = null,
	): Template\TemplateCollection
	{
		$entities = [];

		foreach ($templates as $template)
		{
			$templateId = (int)($template['ID'] ?? 0);

			$entities[]= $this->mapToEntity(
				template: $template,
				responsibles: $users?->filter(static fn (User $user) => in_array($user->getId(), $template['RESPONSIBLE_IDS'], true)),
				rights: $rights[$templateId] ?? null,
				subTemplateIds: $subTemplateIds[$templateId] ?? null,
			);
		}

		return new Template\TemplateCollection(...$entities);
	}

	public function mapSubTemplateIdsCollection(
		array $ids,
		array $subTemplateIds,
	): Template\TemplateCollection
	{
		$entities = [];

		foreach ($ids as $id)
		{
			$entities[]= new Template(
				id: $id,
				subTemplateIds: $subTemplateIds[$id] ?? null,
			);
		}

		return new Template\TemplateCollection(...$entities);
	}
}
