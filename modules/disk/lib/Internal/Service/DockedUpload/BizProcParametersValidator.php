<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

/**
 * Validates the required BizProc autostart parameters before a docked commit
 * (API-03), porting the legacy
 * {@see \DiskFolderListAjaxController::processActionValidateParameterAutoloadBizProc}.
 *
 * For a folder/batch the parameter set is validated once per (storage, scenario)
 * pair (AC-078), not per file. The very same handle serves a freshly collected set
 * and one restored after a page reload (AC-095): a restored set is an ordinary set
 * that happens to be re-submitted, so it needs no endpoint and no contract field of
 * its own - only the unconditional server verdict, which is what makes keeping the
 * sets on the client safe at all.
 *
 * The composition of the required parameters is resolved SERVER-SIDE from the actual
 * parameterized autostart templates of the pair ({@see BizProcParameterDescriptorProvider}
 * runs the same template selection as the commit gate
 * {@see \Bitrix\Disk\BizProcDocument::hasUnmetRequiredAutoStartParameters}). The caller's
 * own idea of what is required is never trusted: a set restored from client storage would
 * otherwise pass against an empty list supplied by that same client and fail on the commit
 * instead, which is exactly the outcome the pre-commit validation exists to prevent.
 */
class BizProcParametersValidator
{
	private const MESSAGE_NOT_FILLED = 'Required workflow parameter is not filled';

	public function __construct(
		private readonly BizProcParameterDescriptorProvider $descriptorProvider,
	)
	{
	}

	/**
	 * Validates a parameter set for the (storage, scenario) pair (AC-078).
	 *
	 * Read-path only: a rejection changes nothing durable - no item is failed and no
	 * queue is cancelled (AC-096). Waiting for parameters is the client's state, and it
	 * is scoped to the queues of this pair alone.
	 *
	 * @param array<string, mixed> $parameters composite paramId => value (scalar or field descriptor)
	 * @param bool $isBizProcEnabled the storage's BizProc flag: a storage with BizProc
	 *   switched off requires nothing, whatever templates exist for it
	 * @return array{valid: bool, storageId: int, scenario: string, errors: array<int, array{paramId: string, message: string}>}
	 */
	public function validate(
		int $storageId,
		string $scenario,
		array $parameters,
		bool $isBizProcEnabled = true
	): array
	{
		$descriptors = $this->descriptorProvider->getAutoStartParameters($storageId, $scenario, $isBizProcEnabled);
		if (!$descriptors->isSuccess())
		{
			// A pair whose templates cannot be resolved (an unknown scenario) cannot be
			// shown to require nothing, so it is rejected rather than waved through. The
			// field-less entry keeps the {paramId, message} wire shape of the contract.
			return $this->verdict($storageId, $scenario, [
				['paramId' => '', 'message' => $descriptors->getErrors()[0]->getMessage()],
			]);
		}

		$errors = [];
		foreach ($descriptors->getData()['required'] as $paramId)
		{
			$paramId = (string)$paramId;
			if (!$this->isFilled($parameters[$paramId] ?? null))
			{
				$errors[] = ['paramId' => $paramId, 'message' => self::MESSAGE_NOT_FILLED];
			}
		}

		return $this->verdict($storageId, $scenario, $errors);
	}

	/**
	 * @param array<int, array{paramId: string, message: string}> $errors
	 * @return array{valid: bool, storageId: int, scenario: string, errors: array<int, array{paramId: string, message: string}>}
	 */
	private function verdict(int $storageId, string $scenario, array $errors): array
	{
		return [
			'valid' => $errors === [],
			'storageId' => $storageId,
			'scenario' => $scenario,
			'errors' => $errors,
		];
	}

	/**
	 * Whether a single parameter value satisfies its requirement.
	 *
	 * HTML field descriptors carry the value in TEXT, so emptiness is judged on
	 * TEXT; other descriptors/scalars are judged on the value itself. Nested
	 * descriptors (a list of typed entries) are treated as filled only when every
	 * HTML entry has a non-empty TEXT.
	 */
	private function isFilled(mixed $value): bool
	{
		if (is_array($value))
		{
			if (isset($value['TYPE']))
			{
				return $this->isDescriptorFilled($value);
			}

			// A list of typed descriptors - filled only if none is an empty HTML.
			foreach ($value as $entry)
			{
				if (is_array($entry) && !$this->isDescriptorFilled($entry))
				{
					return false;
				}
			}

			return !empty($value);
		}

		return $value !== null && $value !== '' && $value !== false;
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	private function isDescriptorFilled(array $descriptor): bool
	{
		if (($descriptor['TYPE'] ?? null) === 'html')
		{
			return !empty($descriptor['TEXT']);
		}

		// A non-HTML descriptor: any non-empty payload beyond the TYPE marker.
		foreach ($descriptor as $key => $entry)
		{
			if ($key === 'TYPE')
			{
				continue;
			}
			if (!empty($entry))
			{
				return true;
			}
		}

		return false;
	}
}
