<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Command;

use Bitrix\Anonymizer\Internal\Entities\Replacement\ReplacementDto;
use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Command;
use Bitrix\Anonymizer\Internal\Services\Replacement\Storage;
use Bitrix\Anonymizer\Public\Resolvers\ReplaceResolverInterface;
use Bitrix\Anonymizer\Public\Resolvers\ResolverInterface;

/**
 * Anonymize plain text via proxy. Registered in {@see \Bitrix\Anonymizer\Internal\Repository\CommandRegistry}.
 */
class AnonymizeText extends Command implements TextCommandInterface
{
	protected string $text;

	public static function getCode(): string
	{
		return 'anonymize_text';
	}

	public function getProxyAction(): string
	{
		return 'anonymizerproxy.api.Anonymize.text';
	}

	public function setText(string $text): void
	{
		$this->text = $text;
	}

	public function getData(): array
	{
		return [
			'text' => $this->text,
			'operators' => [
				// todo: operators is worked?
				"PERSON" => "mask",
				"PHONE" => "mask",
				"EMAIL" => "replace",
			],
			'language' => $this->getLang(),
		];
	}

	public function processResponse(int $questId, array $rawResult): void
	{
		$storage = Storage::loadForQuest($questId);
		$this->appendReplacementsFromRawResult($storage, $rawResult);
		$storage->saveForQuest($questId);
	}

	/**
	 * @param array<string, mixed> $rawResult
	 */
	private function appendReplacementsFromRawResult(Storage $storage, array $rawResult): void
	{
		foreach ($rawResult['entities_detected'] ?? [] as $row)
		{
			if (!isset($row['text'], $row['label'], $row['start'], $row['end']))
			{
				continue;
			}

			$dto = new ReplacementDto();
			$dto->text = (string)$row['text'];
			$dto->label = (string)$row['label'];
			$dto->start = (int)$row['start'];
			$dto->end = (int)$row['end'];

			$storage->addReplacement($dto);
		}
	}

	public function fillResolver(int $questId, ResolverInterface $resolver): void
	{
		if (!$resolver instanceof ReplaceResolverInterface)
		{
			throw new \InvalidArgumentException(
				'Resolver '
				. $resolver::class
				. ' must implement '
				. ReplaceResolverInterface::class,
			);
		}

		$resolver->setReplacements(Storage::loadForQuest($questId));
	}
}
