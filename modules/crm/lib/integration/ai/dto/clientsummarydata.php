<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Dto;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * The answer of the model to a client summary and the whole of its contract (DTO-01): four blocks in
 * the order of the rules, a heading and a content of each, and a note about the completeness of the
 * data. The same contract is worded to the model by
 * {@see \Bitrix\Crm\V2\Internal\Service\AiSummary\SummaryRules::jsonContract()}.
 *
 * The properties are named by the keys of that contract and not by the style of the module: the
 * operation hands the decoded answer of the model to the constructor as it came
 * ({@see \Bitrix\Crm\Integration\AI\Operation\GenerateClientSummary::extractPayloadFromAIResult()}),
 * and a renamed key would arrive as a field of nobody.
 *
 * The check lives here rather than beside the reader of the answer because the framework of the AI
 * operations asks a payload whether it is valid twice - right after the model has answered and again
 * when the stored result is read back - and both times it asks the Dto. An answer that breaks the
 * contract is an error of generation: it never becomes a message in the chat.
 */
final class ClientSummaryData extends Dto
{
	public const BLOCK_COUNT = 4;

	public const ERROR_WRONG_BLOCKS = 'CLIENT_SUMMARY_WRONG_BLOCKS';
	public const ERROR_WRONG_BLOCK_PART = 'CLIENT_SUMMARY_WRONG_BLOCK_PART';
	public const ERROR_WRONG_COVERAGE_NOTE = 'CLIENT_SUMMARY_WRONG_COVERAGE_NOTE';

	private const CONTRACT_FIELDS = ['blocks', 'coverage_note'];

	/** @var array<int, array{title: string, content: string}> */
	public array $blocks = [];

	/** The note about the completeness of the data; null when nothing was cut off or hidden. */
	public ?string $coverage_note = null;

	/**
	 * Only the fields of the contract are read. A model that answered with a key nobody asked for is
	 * not a reason to refuse a summary that has everything a message needs, and a result read back from
	 * the queue carries the keys the framework of the operations writes beside the payload
	 * ({@see \Bitrix\Crm\Integration\AI\Operation\AbstractOperation::constructPayload()} drops the same
	 * ones before it builds a payload of its own).
	 */
	public function __construct(?array $fields = null)
	{
		parent::__construct(
			$fields === null
				? null
				: array_intersect_key($fields, array_flip(self::CONTRACT_FIELDS))
		);
	}

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName)
		{
			'blocks' => new Caster\RawArrayCaster(),
			// a note of another type is a violation the validator below reports; the default cast to
			// a string would quietly turn an array of the model into the word "Array"
			'coverage_note' => new class extends Caster {
				protected function castSingleValue($value)
				{
					return is_string($value) ? $value : null;
				}
			},
			default => null,
		};
	}

	/**
	 * The headings themselves are not checked against the rules: the model writes them in the language
	 * of the portal, so a comparison with the Russian wording of the rules would refuse every answer
	 * of a portal with another language.
	 */
	protected function getValidators(array $fields): array
	{
		return [
			new class($this) extends Validator {
				public function validate(array $fields): Result
				{
					$result = new Result();

					$blocks = $fields['blocks'] ?? null;
					if (!is_array($blocks) || !array_is_list($blocks) || count($blocks) !== ClientSummaryData::BLOCK_COUNT)
					{
						$result->addError(new Error(
							'The summary is made of exactly ' . ClientSummaryData::BLOCK_COUNT . ' blocks',
							ClientSummaryData::ERROR_WRONG_BLOCKS,
						));
					}
					else
					{
						foreach ($blocks as $index => $block)
						{
							foreach (['title', 'content'] as $part)
							{
								$value = is_array($block) ? ($block[$part] ?? null) : null;
								if (!is_string($value) || trim($value) === '')
								{
									$result->addError(new Error(
										"The {$part} of the block {$index} is not a filled string",
										ClientSummaryData::ERROR_WRONG_BLOCK_PART,
									));
								}
							}
						}
					}

					$coverageNote = $fields['coverage_note'] ?? null;
					if ($coverageNote !== null && !is_string($coverageNote))
					{
						$result->addError(new Error(
							'The coverage note is neither a string nor null',
							ClientSummaryData::ERROR_WRONG_COVERAGE_NOTE,
						));
					}

					return $result;
				}
			},
		];
	}
}
