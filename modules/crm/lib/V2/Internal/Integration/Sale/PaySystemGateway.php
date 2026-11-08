<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Sale;

use Bitrix\Main\Loader;
use Bitrix\Sale\PaySystem\Manager;

/**
 * The pay systems of the portal a CRM form may be paid through.
 *
 * The set is the narrow one the builder of the form offers: every active pay system of the portal
 * except the internal account and the cash payment, which are means of recording a payment made
 * elsewhere rather than something a visitor of a public form can be sent to. Nothing of the sale
 * module crosses back - the answer is a plain list of identifiers and names.
 *
 * One source answers two questions of the contract: which pay systems the snapshot of the settings
 * publishes, and which identifiers a form may switch off. Two sets would mean refusing a client the
 * very value the snapshot has just offered it.
 *
 * A portal without the sale module has no pay system to name, and that is told apart from a portal
 * having none: the check of the input may not refuse an identifier it can not ask about.
 *
 * The same selection lives in the JSON controller the builder reads it through
 * ({@see \Bitrix\Crm\Controller\Form\PaySystem}), and this is a second implementation of it rather
 * than a shared one. The reason is the boundary of the task that introduced this contract, which is
 * not allowed to touch the legacy path of the form - not any technical obstacle to moving the
 * selection here. The copy is kept as narrow as the rule itself: the recommendations of the
 * salescenter module the controller answers with beside it are neither duplicated nor published, and
 * so is the logo of a pay system. The first task allowed to change that controller turns it into a
 * facade over this gateway; until then, a change of the selection on one side silently diverges from
 * the other, and a client gets refused a pay system the builder offers it.
 *
 * Not final: the snapshot of the settings takes the gateway through the constructor and a test
 * replaces it.
 *
 * @internal
 */
class PaySystemGateway
{
	/** Ways of recording a payment that the public page of a form never sends a visitor to. */
	private const EXCLUDED_ACTIONS = ['inner', 'cash'];

	/**
	 * Active pay systems of the portal, in the order the editor lists them.
	 *
	 * @return null|list<array{id: int, name: string}> null when the sale module is unavailable,
	 *         which is not the same answer as "the portal has no pay system"
	 */
	public function getActivePaySystems(): ?array
	{
		if (!Loader::includeModule('sale'))
		{
			return null;
		}

		$rows = Manager::getList([
			'select' => ['ID', 'NAME'],
			'filter' => [
				'!=ACTION_FILE' => self::EXCLUDED_ACTIONS,
				'=ACTIVE' => 'Y',
			],
			'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		return array_map(
			static fn (array $row): array => [
				'id' => (int)$row['ID'],
				'name' => (string)$row['NAME'],
			],
			$rows,
		);
	}

	/**
	 * Identifiers of the same pay systems, for the check that has no use for their names.
	 *
	 * @return null|list<int> null when the sale module is unavailable
	 */
	public function getActivePaySystemIds(): ?array
	{
		$paySystems = $this->getActivePaySystems();

		return $paySystems === null ? null : array_column($paySystems, 'id');
	}
}
