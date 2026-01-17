<?php

namespace Bitrix\Tasks\Control;

use Bitrix\Tasks\Internals\Task\Template\TemplateTagTable;

class TemplateTag
{
	public function __construct(
		private readonly int $templateId,
		private readonly int $userId,
	)
	{
	}

	public function add(array $data): void
	{
		if (
			!array_key_exists('TAGS', $data)
			|| !is_array($data['TAGS'])
		)
		{
			return;
		}

		$this->saveTags($data);
	}

	public function set(array $data): void
	{
		if (
			!array_key_exists('TAGS', $data)
			|| !is_array($data['TAGS'])
		)
		{
			return;
		}

		$this->deleteByTemplateId();

		$this->saveTags($data);
	}

	private function saveTags(array $data): void
	{
		if (empty($data['TAGS']))
		{
			return;
		}

		$tags = array_values($data['TAGS']);

		if (empty($tags))
		{
			return;
		}

		$templateTags = [];
		foreach ($tags as $tag)
		{
			$templateTags[] = [
				'NAME' => $tag,
				'TEMPLATE_ID' => $this->templateId,
				'USER_ID' => $this->userId,
			];
		}

		TemplateTagTable::addInsertIgnoreMulti($templateTags, true);
	}

	private function deleteByTemplateId(): void
	{
		TemplateTagTable::deleteList([
			'TEMPLATE_ID' => $this->templateId,
		]);
	}
}
