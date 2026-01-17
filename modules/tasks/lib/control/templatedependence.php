<?php

namespace Bitrix\Tasks\Control;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Control\Exception\TemplateNotFoundException;
use Bitrix\Tasks\Internals\Task\Template\TemplateDependenceTable;
use Bitrix\Tasks\Internals\Task\Template\TemplateMemberTable;
use Bitrix\Tasks\Internals\Task\Template\TemplateObject;

class TemplateDependence
{
	public function __construct(
		private readonly int $templateId
	)
	{
	}

	public function add(array $data): void
	{
		if (
			!array_key_exists('DEPENDS_ON', $data)
			|| !is_array($data['DEPENDS_ON'])
		)
		{
			return;
		}

		$this->saveDependencies($data);
	}

	public function set(array $data): void
	{
		if (
			!array_key_exists('DEPENDS_ON', $data)
			|| !is_array($data['DEPENDS_ON'])
		)
		{
			return;
		}

		$this->deleteByTemplateId();

		$this->saveDependencies($data);
	}

	private function saveDependencies(array $data): void
	{
		if (empty($data['DEPENDS_ON']))
		{
			return;
		}

		$depends = array_values($data['DEPENDS_ON']);

		if (empty($depends))
		{
			return;
		}

		$templateDependencies = [];
		foreach ($depends as $dependId)
		{
			$templateDependencies[] = [
				'TEMPLATE_ID' => $this->templateId,
				'DEPENDS_ON_ID' => $dependId,
			];
		}

		TemplateDependenceTable::addInsertIgnoreMulti($templateDependencies, true);
	}

	private function deleteByTemplateId(): void
	{
		TemplateDependenceTable::deleteList([
			'TEMPLATE_ID' => $this->templateId,
		]);
	}
}
