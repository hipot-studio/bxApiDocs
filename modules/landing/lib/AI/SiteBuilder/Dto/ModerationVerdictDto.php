<?php

declare(strict_types=1);

namespace Bitrix\Landing\AI\SiteBuilder\Dto;

/**
 * Verdict of the AI-site topic moderation step.
 *
 * The machine contract (decision + category codes) is the source of truth for branching
 * and metrics. The category set comes from the approved CATEGORIES-forbidden-topics-draft.md.
 */
final class ModerationVerdictDto extends AbstractDto
{
	public const DECISION_ALLOW = 'allow';
	public const DECISION_SOFT_BLOCK = 'soft_block';
	public const DECISION_HARD_BLOCK = 'hard_block';

	public const DECISIONS = [
		self::DECISION_ALLOW,
		self::DECISION_SOFT_BLOCK,
		self::DECISION_HARD_BLOCK,
	];

	public const CATEGORY_NONE = 'none';

	/**
	 * Category codes from the approved CATEGORIES-forbidden-topics-draft.md (single source of truth).
	 */
	public const CATEGORY_CODES = [
		self::CATEGORY_NONE,
		// hard
		'csam',
		'terrorism',
		'drugs',
		'weapons',
		'fraud_malware',
		'human_trafficking',
		'forgery',
		'gambling',
		'war_politics',
		'escort',
		// soft
		'alcohol_tobacco',
		'medical',
		'finance_crypto',
		'adult',
		'impersonation',
	];

	private string $decision;
	private string $category;
	private string $hint;

	private function __construct(string $decision, string $category, string $hint)
	{
		$this->decision = $decision;
		$this->category = $category;
		$this->hint = $hint;
	}

	/**
	 * Builds the verdict from the model response. Returns null on an invalid decision/category
	 * — a failure signal for verifyResponse (fail-closed).
	 */
	public static function fromArray(array $data): ?self
	{
		$decision = is_scalar($data['decision'] ?? null) ? (string)$data['decision'] : '';
		if (!in_array($decision, self::DECISIONS, true))
		{
			return null;
		}

		$category = is_scalar($data['category'] ?? null) ? (string)$data['category'] : '';
		if (!in_array($category, self::CATEGORY_CODES, true))
		{
			return null;
		}

		$hint = is_scalar($data['hint'] ?? null) ? (string)$data['hint'] : '';
		$hint = self::normalizeText($hint);
		// The rewording hint is meaningful only for soft_block.
		if ($decision !== self::DECISION_SOFT_BLOCK)
		{
			$hint = '';
		}

		return new self($decision, $category, $hint);
	}

	public function getDecision(): string
	{
		return $this->decision;
	}

	public function getCategory(): string
	{
		return $this->category;
	}

	public function getHint(): string
	{
		return $this->hint;
	}

	public function isAllowed(): bool
	{
		return $this->decision === self::DECISION_ALLOW;
	}

	public function isSoftBlock(): bool
	{
		return $this->decision === self::DECISION_SOFT_BLOCK;
	}

	public function isHardBlock(): bool
	{
		return $this->decision === self::DECISION_HARD_BLOCK;
	}

	private static function normalizeText(string $value): string
	{
		$value = preg_replace('/\s+/u', ' ', $value) ?: $value;

		return trim($value);
	}
}
