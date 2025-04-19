<?php

use Bitrix\Main\Localization\Loc;

/** @var array $params */

$invoiceSum = $params['INVOICE_SUM_FORMATTED'] ?? '';
$invoiceUrl = $params['INVOICE_URL'] ?? '';
$dueDate = $params['INVOICE_DUE_DATE'] ?? '';

?>
<div class="mb-4">
	<div class="widget-payment-checkout-info"><?= Loc::getMessage('SALE_HANDLERS_PAY_SYSTEM_TBANK_BUSINESS_DESCRIPTION') ?></div>
	<div class="widget-payment-checkout-info">
		<?php
		echo Loc::getMessage(
			'SALE_HANDLERS_PAY_SYSTEM_TBANK_BUSINESS_INVOICE_SUM',
			[
				'#INVOICE_SUM#' => $invoiceSum,
			]
		);
		?>
	</div>
	<?php
	if ($dueDate !== ''):
		?>
		<div class="widget-payment-checkout-info">
			<?php
			echo Loc::getMessage(
				'SALE_HANDLERS_PAY_SYSTEM_TBANK_BUSINESS_INVOICE_DUE_DATE',
				[
					'#INVOICE_DUE_DATE#' => $dueDate,
				]
			);
			?>
		</div>
	<?php
	endif;
	if ($invoiceUrl !== ''):
		?>
		<div class="widget-payment-checkout-info">
			<?php
			echo Loc::getMessage(
				'SALE_HANDLERS_PAY_SYSTEM_TBANK_BUSINESS_INVOICE_SHOW',
				[
					'#INVOICE_URL#' => $invoiceUrl,
				]
			);
			?>
		</div>
		<?php
	endif;
	?>
</div>
