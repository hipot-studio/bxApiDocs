<?php

namespace Bitrix\Rest\V3\Mcp\Verb;

final class ResolvedVerb
{
	public function __construct(
		public readonly string $code,
		private readonly string $descriptionTemplate,
		private readonly ?bool $readOnlyHint = null,
		private readonly ?bool $destructiveHint = null,
		private readonly ?bool $idempotentHint = null,
	)
	{
	}

	public function getDescription(string $entityTitle): string
	{
		return sprintf($this->descriptionTemplate, $entityTitle);
	}

	public function getAnnotations(): ?object
	{
		$annotations = array_filter(
			[
				'readOnlyHint' => $this->readOnlyHint,
				'destructiveHint' => $this->destructiveHint,
				'idempotentHint' => $this->idempotentHint,
			],
			static fn(mixed $value): bool => $value !== null,
		);

		return $annotations === [] ? null : (object)$annotations;
	}
}
