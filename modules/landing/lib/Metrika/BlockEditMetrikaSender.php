<?php
declare(strict_types=1);

namespace Bitrix\Landing\Metrika;

/**
 * The only place that builds the block edition event.
 *
 * Callers pass facts of what happened - the site type and either the changed content kind
 * (manual edit) or the outcome of the generation (AI edit) - and never the event fields.
 * The section of the product is told apart by the tool, the category is common for all of them.
 */
class BlockEditMetrikaSender
{
	/**
	 * Position of the changed content kind in the params of the event.
	 */
	private const CONTENT_PARAM_POSITION = 1;

	/**
	 * Name of the changed content kind param.
	 */
	private const CONTENT_PARAM_NAME = 'content';

	/**
	 * Built on demand by the only consumer - the AI edit. Building the service pulls the tuning
	 * manager of the ai module, which broadcasts an event to every module of the portal, and the
	 * manual edit, the hot path of the editor, does not use the provider param at all.
	 */
	private ?MetrikaProviderParamService $providerParamService;

	public function __construct(?MetrikaProviderParamService $providerParamService = null)
	{
		$this->providerParamService = $providerParamService;
	}

	/**
	 * The user has changed the content of the block by hand.
	 *
	 * @param string $siteType Type of the site the block belongs to.
	 * @param BlockContentKinds $contentKind Kind of the changed content.
	 */
	public function sendManualEdit(string $siteType, BlockContentKinds $contentKind): void
	{
		$this
			->createMetrika($siteType)
			->setType(Types::manual)
			->setParam(self::CONTENT_PARAM_POSITION, self::CONTENT_PARAM_NAME, $contentKind->value)
			->send()
		;
	}

	/**
	 * The user has changed the block through AI.
	 *
	 * @param string $siteType Type of the site the block belongs to.
	 * @param Statuses|null $status Status of the outcome, null means success.
	 * @param string|null $errorCode Code of the generation error, null means success.
	 */
	public function sendAiEdit(string $siteType, ?Statuses $status = null, ?string $errorCode = null): void
	{
		$metrika = $this->createMetrika($siteType)->setType(Types::ai);
		$this->providerParamService ??= new MetrikaProviderParamService();
		$this->providerParamService->setParams($metrika, Events::edit);

		if ($errorCode !== null && $errorCode !== '')
		{
			$metrika->setError($errorCode, $status);
		}
		else
		{
			$metrika->setStatus($status);
		}

		$metrika->send();
	}

	private function createMetrika(string $siteType): Metrika
	{
		return new Metrika(
			Categories::BlockEdition,
			Events::edit,
			Tools::getBySiteType($siteType),
		);
	}
}
