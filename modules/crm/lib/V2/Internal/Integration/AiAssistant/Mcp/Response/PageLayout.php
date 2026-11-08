<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;

use Bitrix\Crm\Integration\UI\EntityEditor\Configuration;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Infrastructure\Component\Details\PageLayout\PageLayoutId;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response\PageLayout\Element;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response\PageLayout\Section;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class PageLayout implements JsonSerializable, Arrayable
{
	public function __construct(
		public string $id,
		public string $title,
		public array $sections,
	)
	{
	}

	public static function fromConfiguration(Configuration $configuration, string $title, int $entityTypeId, ?int $categoryId, string $scope, ?int $userScopeId = null): ?self
	{
		$pageLayoutId = new PageLayoutId(
			$categoryId,
			$scope,
			$userScopeId,
		);

		$id = $pageLayoutId->getCode();
		if ($id === null)
		{
			return null;
		}

		$fields = Container::getInstance()
			->getFactory($entityTypeId)
			?->getFieldsCollection()
		;

		$sections = [];
		foreach ($configuration->getSections() as $section)
		{
			$elements = [];
			foreach ($section->getElements() as $element)
			{
				$field = $fields->getField($element->getName());
				if ($field === null)
				{
					continue;
				}

				$elements[] = new Element($element->getName(), $field->getTitle());
			}

			$sections[] = new Section(
				id: $section->getName(),
				title: $section->getTitle(),
				elements: $elements,
			);
		}

		return new self($id, $title, $sections);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'sections' => array_map(static fn (Section $section) => $section->toArray(), $this->sections),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
