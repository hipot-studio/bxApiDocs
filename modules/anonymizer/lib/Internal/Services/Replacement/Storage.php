<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Services\Replacement;

use Bitrix\Anonymizer\Internal\Entities\Replacement\ReplacementDto;
use Bitrix\Anonymizer\Internal\Models\ReplacementsTable;
use Bitrix\Main\Security\Random;

/**
 * Persistence and in-memory collection of replacements. Does not parse API responses.
 */
class Storage
{
	/**
	 * @var ReplacementDto[]
	 */
	private array $replacements = [];

	public static function loadForQuest(int $questId): self
	{
		$storage = new self();
		$storage->loadFromQuest($questId);

		return $storage;
	}

	public function addReplacement(ReplacementDto $replacement): self
	{
		if ($this->hasEntityKey(ReplacementDto::entityKey($replacement)))
		{
			return $this;
		}

		if (!isset($replacement->code) || $replacement->code === '')
		{
			$replacement->code = $this->generateCode();
		}
		$this->replacements[$replacement->code] = $replacement;

		return $this;
	}

	public function getReplacements(): array
	{
		return $this->replacements;
	}

	public function saveForQuest(int $questId): void
	{
		$existingKeys = $this->loadExistingEntityKeys($questId);

		foreach ($this->replacements as $replacement)
		{
			$key = ReplacementDto::entityKey($replacement);
			if (isset($existingKeys[$key]))
			{
				continue;
			}

			ReplacementsTable::add([
				'QUEST_ID' => $questId,
				'CODE' => $replacement->code,
				'TEXT' => $replacement->text,
				'LABEL' => $replacement->label,
				'START' => $replacement->start,
				'END' => $replacement->end,
			]);

			$existingKeys[$key] = true;
		}
	}

	private function hasEntityKey(string $key): bool
	{
		foreach ($this->replacements as $replacement)
		{
			if (ReplacementDto::entityKey($replacement) === $key)
			{
				return true;
			}
		}

		return false;
	}

	private function loadFromQuest(int $questId): void
	{
		$this->replacements = [];

		$res = ReplacementsTable::query()
			->setSelect(['CODE', 'TEXT', 'LABEL', 'START', 'END'])
			->where('QUEST_ID', $questId)
			->exec()
		;
		while ($row = $res->fetch())
		{
			$dto = new ReplacementDto();
			$dto->code = (string)$row['CODE'];
			$dto->text = (string)$row['TEXT'];
			$dto->label = (string)$row['LABEL'];
			$dto->start = (int)$row['START'];
			$dto->end = (int)$row['END'];

			$this->replacements[] = $dto;
		}
	}

	/**
	 * @return array<string, true>
	 */
	private function loadExistingEntityKeys(int $questId): array
	{
		$keys = [];

		$res = ReplacementsTable::query()
			->setSelect(['LABEL', 'START', 'END'])
			->where('QUEST_ID', $questId)
			->exec()
		;
		while ($row = $res->fetch())
		{
			$dto = new ReplacementDto();
			$dto->label = (string)$row['LABEL'];
			$dto->start = (int)$row['START'];
			$dto->end = (int)$row['END'];
			$keys[ReplacementDto::entityKey($dto)] = true;
		}

		return $keys;
	}

	private function generateCode(): string
	{
		$existingCodes = [];
		foreach ($this->replacements as $replacement)
		{
			$existingCodes[$replacement->code] = true;
		}

		do
		{
			$code = Random::getString(8);
		}
		while (isset($existingCodes[$code]));

		return $code;
	}

	public function sortByPosition(bool $asc = true): self
	{
		usort($this->replacements, static function ($a, $b) use ($asc)
		{
			return $asc
				? $a->start <=> $b->start
				: $b->start <=> $a->start;
		});

		return $this;
	}
}
