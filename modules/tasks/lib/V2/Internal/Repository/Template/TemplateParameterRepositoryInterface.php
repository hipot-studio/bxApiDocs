<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

interface TemplateParameterRepositoryInterface
{
	public function link(int $templateId, array $params): void;

	public function updateLinks(int $templateId, array $params): void;
}
