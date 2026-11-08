<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Assembler\Field\JsFields;

use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version\VersionCellResolver;
use Bitrix\Main\Grid\Settings;

/**
 * Renders the version cell of an AI-agents grid row.
 *
 * Only the two axes of VersionCellDto reach the client. The installed-version label is derived
 * from the copy logic hash, and the column is visible to every user of the list, so the labels
 * must never be added to the render params.
 */
class VersionFieldAssembler extends JsExtensionFieldAssembler
{
	private VersionCellResolver $resolver;

	public function __construct(array $columnIds, ?Settings $settings = null)
	{
		parent::__construct($columnIds, $settings);

		$this->resolver = new VersionCellResolver();
	}

	protected function getExtensionClassName(): string
	{
		return 'VersionField';
	}

	protected function getRenderParams($rawValue): array
	{
		return $this->resolver->resolve($rawValue)->toArray();
	}

	protected function prepareColumnForExport($data): string
	{
		return '';
	}
}
