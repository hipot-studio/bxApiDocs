<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Interface;

use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Error;

/**
 * Interface for all custom servers.
 */
interface CustomServerInterface extends EntityInterface
{
	/**
	 * @return CustomServerTypes|null
	 */
	public function getType(): ?CustomServerTypes;

	/**
	 * @return string|null
	 */
	public function getTitle(): ?string;

	/**
	 * @param string $name
	 * @return string|null
	 */
	public function getAdminTemplatePath(string $name): ?string;

	/**
	 * @return array|null
	 */
	public function getData(): ?array;

	/**
	 * @return string|null
	 */
	public function getName(): ?string;

	/**
	 * @return bool
	 */
	public function isEnabled(): bool;

	/**
	 * @return Error|null
	 */
	public function prepareDataForConnect(): ?Error;

	/**
	 * @return bool
	 */
	public function isConfigured(): bool;

	/**
	 * @return bool
	 */
	public function isReadyForUse(): bool;

	/**
	 * @return array|null
	 */
	public function getAvailableRegions(): ?array;

	/**
	 * @return array|null
	 */
	public function getUnavailableRegions(): ?array;
}
