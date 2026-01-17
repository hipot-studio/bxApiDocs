<?php

namespace Bitrix\Rest\V3\Documentation;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Schema\MethodDescription;
use Bitrix\Rest\V3\Schema\SchemaManager;

class BatchMethodProvider extends MethodProvider
{
	private MethodDescription $methodDescription;

	public function __construct(string $language)
	{
		$schemaManager = ServiceLocator::getInstance()->get(SchemaManager::class);
		$this->methodDescription = $schemaManager->getMethodDescription('batch');
		parent::__construct($language);
	}

	protected function getTags(): array
	{
		return [];
	}

	protected function getRequestBody(): array
	{
		return [
			'content' => [
				'application/json' => [
					'schema' => [
						'type' => 'object',
					],
				],
			],
		];
	}

	protected function getResponses(): array
	{
		return [];
	}

	protected function getTitle(): ?string
	{
		return $this->methodDescription->title ? $this->methodDescription->title->localize($this->language) : null;
	}

	protected function getDescription(): ?string
	{
		return $this->methodDescription->description ? $this->methodDescription->description->localize($this->language) : null;
	}
}
