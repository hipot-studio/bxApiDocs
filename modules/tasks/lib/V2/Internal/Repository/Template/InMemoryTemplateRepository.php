<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\V2\Internal\Entity;

class InMemoryTemplateRepository implements TemplateRepositoryInterface
{
	private TemplateRepositoryInterface $templateRepository;
	private array $cache = [];
	private array $taskIdCache = [];

	public function __construct(TemplateRepository $templateRepository)
	{
		$this->templateRepository = $templateRepository;
	}

	public function getById(int $id): ?Entity\Template
	{
		// Check if the template is already in the cache
		if (isset($this->cache[$id]))
		{
			return $this->cache[$id];
		}

		// Fetch the template from the underlying repository
		$template = $this->templateRepository->getById($id);

		// Cache the template if it exists
		if ($template !== null)
		{
			$this->cache[$id] = $template;
		}

		return $template;
	}

	public function getByTaskId(int $taskId): ?Entity\Template
	{
		// Check if the template is already in the cache
		if (isset($this->taskIdCache[$taskId]))
		{
			return $this->taskIdCache[$taskId];
		}

		// Fetch the template from the underlying repository
		$template = $this->templateRepository->getByTaskId($taskId);

		// Cache the template if it exists
		if ($template !== null)
		{
			$this->taskIdCache[$taskId] = $template;
			$this->cache[$template->id] = $template;
		}

		return $template;
	}

	public function save(Entity\Template $entity): int
	{
		// Remove the template from the cache if it exists
		if (isset($this->cache[$entity->getId()]))
		{
			unset($this->cache[$entity->getId()]);
		}

		// Save the template using the underlying repository
		$templateId = $this->templateRepository->save($entity);

		// Update the cache with the saved template
		$this->cache[$templateId] = $this->getById($templateId);

		return $templateId;
	}

	public function delete(int $id): void
	{
		// Delete the template using the underlying repository
		$this->templateRepository->delete($id);

		// Remove the template from the cache if it exists
		if (isset($this->cache[$id]))
		{
			unset($this->cache[$id]);
		}
	}

	public function invalidate(int $id): void
	{
		unset($this->cache[$id]);
	}
}
