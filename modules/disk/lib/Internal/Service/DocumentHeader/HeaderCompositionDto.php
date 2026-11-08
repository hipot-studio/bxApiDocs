<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DocumentHeader;

/**
 * Description of what a concrete opening of an object puts into the header (DTO-01).
 *
 * Carries no presentation details: no CSS classes, node ids or texts. An element missing from
 * {@see self::$elements} means the header does not render it at all, not that it is disabled.
 */
final class HeaderCompositionDto
{
	public const OBJECT_TYPE_DOCUMENT = 'document';
	public const OBJECT_TYPE_BOARD = 'board';

	public const MODE_VIEW = 'view';
	public const MODE_EDIT = 'edit';

	/**
	 * @param string[] $elements element values in render order
	 * @param int|null $objectId null for a public opening, which is told nothing of the object inside the portal
	 * @param string|null $objectUniqueCode null for a public opening, for the same reason
	 * @param string $unifiedLinkUrl the address the copy action hands out, whose form is the one of the
	 *        opening (D-1): the unified link of the object for an opening of the portal, the public
	 *        external link itself for a page entered by one. Empty when the opening keeps no copying.
	 * @param string $downloadUrl empty when the opening keeps no downloading
	 * @param array<int, array{code: string, name: string, isDefault: bool}> $editors
	 */
	public function __construct(
		public readonly array $elements,
		public readonly string $objectType,
		public readonly string $mode,
		public readonly ?int $objectId,
		public readonly string $objectName,
		public readonly ?string $objectUniqueCode,
		public readonly string $objectExtension,
		public readonly string $unifiedLinkUrl,
		public readonly string $downloadUrl,
		public readonly string $accessControlType,
		public readonly array $editors,
		public readonly bool $presenceEnabled,
		public readonly bool $boostContainerRequired,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'elements' => $this->elements,
			'objectType' => $this->objectType,
			'mode' => $this->mode,
			'object' => [
				'id' => $this->objectId,
				'name' => $this->objectName,
				'uniqueCode' => $this->objectUniqueCode,
				'extension' => $this->objectExtension,
			],
			'urls' => [
				'unifiedLink' => $this->unifiedLinkUrl,
				'download' => $this->downloadUrl,
			],
			'access' => [
				'controlType' => $this->accessControlType,
			],
			'editors' => $this->editors,
			'presence' => [
				'enabled' => $this->presenceEnabled,
			],
			'boost' => [
				'containerRequired' => $this->boostContainerRequired,
			],
		];
	}
}
