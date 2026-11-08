<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version;

/**
 * DTO-01: upgrade/customization state of a launched AI-agent copy for a grid row.
 *
 * The field names and their semantics are an owner contract that frontend relies
 * on. Do not rename or repurpose them.
 */
final class UpgradeAvailabilityDto
{
	public function __construct(
		/** A newer reference version is available (system template revision != copy installed version). */
		public readonly bool $hasNewVersion = false,
		/** Copy logic diverged from its installed version (copy logic revision != installed version). */
		public readonly bool $isCustomized = false,
		/** Human-readable label of the installed version (for the upgrade dialog). */
		public readonly string $currentVersionLabel = '',
		/** Human-readable label of the available reference version; null when no upgrade. */
		public readonly ?string $newVersionLabel = null,
		/**
		 * The copy was actually compared against its reference, so hasNewVersion and isCustomized
		 * carry an answer. False means the comparison did not happen (no installed version, no
		 * reference, or a revision that could not be computed) and both flags mean nothing:
		 * the two of them being false must not be read as "up to date".
		 */
		public readonly bool $isComparable = false,
		/** SIG-ORIGIN: the copy is linked to a system template, even if that template is gone. */
		public readonly bool $hasSystemOrigin = false,
	) {}

	/**
	 * @return array{
	 *     hasNewVersion: bool,
	 *     isCustomized: bool,
	 *     currentVersionLabel: string,
	 *     newVersionLabel: string|null,
	 *     isComparable: bool,
	 *     hasSystemOrigin: bool,
	 * }
	 */
	public function toArray(): array
	{
		return [
			'hasNewVersion' => $this->hasNewVersion,
			'isCustomized' => $this->isCustomized,
			'currentVersionLabel' => $this->currentVersionLabel,
			'newVersionLabel' => $this->newVersionLabel,
			'isComparable' => $this->isComparable,
			'hasSystemOrigin' => $this->hasSystemOrigin,
		];
	}
}
