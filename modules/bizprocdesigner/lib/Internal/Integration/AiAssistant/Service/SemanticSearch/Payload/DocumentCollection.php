<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\SemanticSearch\Payload;

use Bitrix\BizprocDesigner\Internal\Entity\Collection\AbstractCollection;

class DocumentCollection extends AbstractCollection
{
	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof Document;
	}

	public static function fromArray(array $documents): static
	{
		$instance = new static();
		foreach ($documents as $document)
		{
			if (is_array($document) && isset($document['id'], $document['name']))
			{
				$instance->add(new Document(
					id: $document['id'],
					name: $document['name'],
					description: $document['description'] ?? '',
					extraData: $document['extra_data'] ?? []
				));
			}
		}

		return $instance;
	}
}