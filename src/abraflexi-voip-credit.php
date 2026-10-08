<?php

declare(strict_types=1);

/**
 * This file is part of the AbraFlexi VoIP Credit package
 *
 * https://github.com/Spoje-NET/abraflexi-voip-credit
 *
 * (c) Vítězslav Dvořák <http://spojenet.cz/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet\AbraFlexiVoipCredit;

\define('APP_NAME', 'AbraFlexiVoipCredit');

require_once '../vendor/autoload.php';

$options = getopt('o::e::i:', ['output::', 'environment::', 'invoice:']);

\Ease\Shared::init(
    ['ABRAFLEXI_URL', 'ABRAFLEXI_LOGIN', 'ABRAFLEXI_PASSWORD', 'ABRAFLEXI_COMPANY', 'IPEX_URL', 'IPEX_LOGIN', 'IPEX_PASSWORD'],
    $options['environment'] ?? $options['e'] ?? '../.env',
);

$destination = $options['output'] ?? $options['o'] ?? \Ease\Shared::cfg('RESULT_FILE', 'php://stdout');
$invoiceId = $options['invoice'] ?? $options['i'] ?? \Ease\Shared::cfg('INVOICE_ID', \Ease\Shared::cfg('EVENT_RECORD_ID', ''));

$credit = new VoipCredit();

if (\Ease\Shared::cfg('APP_DEBUG')) {
    $credit->logBanner();
}

if (empty($invoiceId)) {
    $credit->addStatusMessage(_('INVOICE_ID (or --invoice) is required'), 'error');

    exit(2);
}

$report = $credit->processInvoice(new \AbraFlexi\FakturaVydana(is_numeric($invoiceId) ? (int) $invoiceId : $invoiceId, ['detail' => 'full']));
$report['timestamp'] = (new \DateTimeImmutable())->format(\DATE_ATOM);

$written = file_put_contents($destination, json_encode($report, \Ease\Shared::cfg('APP_DEBUG') ? \JSON_PRETTY_PRINT : 0));
$credit->addStatusMessage(sprintf(_('Saving result to %s'), $destination), $written ? 'success' : 'error');

exit($report['exitcode']);
