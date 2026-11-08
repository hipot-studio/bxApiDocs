<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync;

use Bitrix\Rest\V3\Documentation\MethodProvider;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Exception\RateLimitException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Schema\TypeAliasRegistry;

/**
 * The shared half of the documentation of every disk.sync.* method.
 *
 * The generator of the platform describes a method by its return type and writes a single "200" into
 * responses, so no refusal a method can answer with ever reaches the published document. Every method
 * of the contour therefore brings a provider of its own, and this base is what keeps those providers
 * from restating the contract: a refusal is described by asking the exception itself for the code, the
 * status and the phrase it publishes, and the successful answer is a reference to the DTO the method
 * returns. Nothing here is a literal that could drift away from the code that answers.
 */
abstract class SyncMethodProvider extends MethodProvider
{
	private const MODULE_TAG = 'disk';

	/**
	 * The item of the single-item envelope the method answers with.
	 */
	abstract protected function getItemDto(): Dto;

	/**
	 * Every refusal the method can answer with, as the instances that publish them. The limiter of the
	 * platform is added by this base - it stands in front of every method alike.
	 *
	 * @return RestException[]
	 */
	abstract protected function getRefusals(): array;

	final protected function getTags(): array
	{
		return [self::MODULE_TAG];
	}

	final protected function getResponses(): array
	{
		$responses = ['200' => $this->describeSuccess()];

		foreach ($this->groupRefusalsByStatus() as $status => $answers)
		{
			$responses[(string)$status] = $this->describeRefusals($answers);
		}

		return $responses;
	}

	/**
	 * The single-item envelope, which is what most methods of the contour answer with. A method whose
	 * answer is a page describes its own envelope over the same item.
	 */
	protected function describeSuccess(): array
	{
		$itemReference = '#/components/schemas/' . TypeAliasRegistry::toPublicType($this->getItemDto());

		return [
			'description' => 'The answer of the method.',
			'content' => [
				'application/json' => [
					'schema' => [
						'type' => 'object',
						'properties' => [
							'result' => [
								'type' => 'object',
								'properties' => [
									'item' => ['$ref' => $itemReference],
								],
							],
						],
					],
				],
			],
		];
	}

	/**
	 * @return array<int, array<int, array<string, mixed>>> HTTP status => answers of that status
	 */
	private function groupRefusalsByStatus(): array
	{
		$byStatus = [];

		foreach ([...$this->getRefusals(), new RateLimitException()] as $refusal)
		{
			$byStatus[(int)$refusal->getStatus()][] = $refusal->output($this->language);
		}

		ksort($byStatus);

		return $byStatus;
	}

	/**
	 * One entry per HTTP status, because that is what OpenAPI is keyed by. Which refusal of the status
	 * it is is told by the code, so the codes are both the enumeration of the schema and the examples.
	 *
	 * @param array<int, array<string, mixed>> $answers
	 */
	private function describeRefusals(array $answers): array
	{
		$codes = array_column($answers, 'code');

		$errorProperties = [
			'code' => ['type' => 'string', 'enum' => $codes],
			'message' => ['type' => 'string'],
		];

		// Only the refusals of the validation layer carry it, and only some statuses have such a refusal.
		foreach ($answers as $answer)
		{
			if (array_key_exists('validation', $answer))
			{
				$errorProperties['validation'] = [
					'type' => 'array',
					'description' => 'Present when the request itself was refused. One entry per parameter.',
					'items' => [
						'type' => 'object',
						'properties' => [
							'message' => ['type' => 'string'],
							'field' => ['type' => 'string'],
						],
					],
				];

				break;
			}
		}

		$examples = [];
		foreach ($answers as $answer)
		{
			$examples[$answer['code']] = ['value' => ['error' => $answer]];
		}

		return [
			'description' => implode(', ', $codes),
			'content' => [
				'application/json' => [
					'schema' => [
						'type' => 'object',
						'properties' => ['error' => ['type' => 'object', 'properties' => $errorProperties]],
					],
					'examples' => $examples,
				],
			],
		];
	}
}
