<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

class RelationTemplateMapper
{
	public function mapToEntity(
		array $template,
		?UserCollection $responsibles = null,
		?array $rights = null,
	): Template
	{
		$fields = [
			'id' => $template['ID'] ?? null,
			'title' => $template['TITLE'] ?? null,
			'responsibleCollection' => $responsibles,
			'deadlineAfter' => $template['DEADLINE_AFTER'] ?? null,
			'rights' => $rights,
		];

		return Template::mapFromArray($fields);
	}

	public function mapToCollection(
		array $templates,
		?UserCollection $users = null,
		?array $rights = null,
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
			);
		}

		return new Template\TemplateCollection(...$entities);
	}
}
