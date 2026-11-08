<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Stub;

use Bitrix\Crm\Integration\AI\Operation\Payload\StubInterface;
use Bitrix\Main\Web\Json;

/**
 * Based on a real model response from a development environment. The text is intentionally long
 * enough to stress-test the card and activity description, while the two empty fields reflect the
 * model output for this prompt.
 */
final class RepeatSalesPrompt implements StubInterface
{
	public function makeStub(): string
	{
		$customerInfo = [
			'last_purchase_date' => '27.07.2026',
			'last_purchase_details' => 'Deal closed successfully for 995 USD. Customer ordered: '
				. 'JGL-135-5B forming die; plastic gearbox shaft bushing for JGL-135 / JGL-120-5B; '
				. 'automatic stabilizer angle-of-attack control system; JGT-60 outer nozzle 90x50x8 mm; '
				. 'fiberglass-reinforced EPDM flange gasket for DN20 PN25 steel flanges; '
				. 'solenoid-operated hydraulic directional control valve; '
				. 'tapered adapter sleeve made of grade 45 steel for a 205 bearing; drive shaft coupling; '
				. 'maintenance seal kit.',
			'orders_overview' => 'Based on recent deal history, Kuzma has 2 successful deals '
				. 'totaling 1,526 USD. Purchases included: 1) an order for technical components with a '
				. 'JGL-135-5B forming die, gearbox shaft bushings, an AACS stabilizer control system, '
				. 'a JGT-60 nozzle, an EPDM flange gasket, a hydraulic directional control valve, '
				. 'a tapered adapter sleeve made of grade 45 steel, a drive shaft coupling, and a '
				. 'maintenance seal kit for 995 USD; '
				. '2) an order for an industrial transfer pump (model 6263) for 531 USD. '
				. 'No failed deals were found.',
			'detailed_issues_summary' => '',
		];

		$actionPlan = [
			'best_way_to_contact' => 'No preferred communication channel is available yet. Contact Kuzma '
				. 'directly to remind him about the previous industrial parts orders and check whether it is '
				. 'time to replenish stock.',
			'sales_opportunity' => 'The purchase history suggests that the customer regularly orders '
				. 'specialized industrial components and spare parts. Recommend checking current demand for '
				. 'consumables or replacement parts similar to the last order, such as shaft bushings, nozzles, '
				. 'gaskets, hydraulic valves, seal kits, or pump units, because they may belong to a regular '
				. 'maintenance cycle.',
			'service_improvement_suggestions' => '',
			'think_before_service_improvement_suggestions' => 'Because there are no call or message records, '
				. 'there is not enough evidence to suggest service improvements based on complaints. Given '
				. 'the purchase pattern, the main focus should be a timely reminder about repeat procurement.',
		];

		return Json::encode([
			'customer_info' => $customerInfo,
			'action_plan' => $actionPlan,
		]);
	}
}
