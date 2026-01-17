<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Registry;

use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\Internals\Trait\SingletonTrait;

final class TemplateRegistry
{
	use SingletonTrait;

	private array $storage = [];

	public function get(int $templateId): ?TemplateObject
	{
		if (!isset($this->storage[$templateId]))
		{
			$this->load([$templateId]);
		}

		return $this->storage[$templateId] ?? null;
	}

	public function load(array $templateIds): self
	{
		if (empty($templateIds))
		{
			return $this;
		}

		$templateIds = array_diff(array_unique($templateIds), array_keys($this->storage));

		if (empty($templateIds))
		{
			return $this;
		}

		$select = [
			'ID',
			'DESCRIPTION',
			'ZOMBIE',
			'GROUP_ID',
			'REPLICATE',
			'PARENT.PARENT_TEMPLATE_ID',
		];

		$templates = TemplateTable::query()
			->setSelect($select)
			->whereIn('ID', $templateIds)
			->fetchCollection();

		if ($templates->isEmpty())
		{
			return $this;
		}

		foreach ($templates as $template)
		{
			$this->storage[$template->getId()] = $template;
		}

		return $this;
	}
}
