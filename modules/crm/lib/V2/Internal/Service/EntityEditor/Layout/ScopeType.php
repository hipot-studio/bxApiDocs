<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout;

use Bitrix\Crm\Entity\EntityEditorConfigScope;
use RuntimeException;

/**
 * @internal
 */
enum ScopeType
{
	case Personal;
	case Common;
	case Custom;

	public static function fromConfigurationScope(string $scope): self
	{
		return match ($scope) {
			EntityEditorConfigScope::PERSONAL => self::Personal,
			EntityEditorConfigScope::COMMON => self::Common,
			EntityEditorConfigScope::CUSTOM => self::Custom,
			default => throw new RuntimeException('The entity editor returned an invalid layout scope.'),
		};
	}

	public function toConfigurationScope(): string
	{
		return match ($this) {
			self::Personal => EntityEditorConfigScope::PERSONAL,
			self::Common => EntityEditorConfigScope::COMMON,
			self::Custom => EntityEditorConfigScope::CUSTOM,
		};
	}
}
