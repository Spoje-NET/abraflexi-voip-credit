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

/**
 * Add IPEX prepaid VoIP credit for a paid AbraFlexi invoice containing KREDIT_VOIP.
 *
 * Headless successor of SpojeNet\System\orderplugins\VoIPcredit::settled().
 * Target numbers come from the order.json attachment of the invoice
 * (ipexuser, phoneno, cenaMj, notify). The "API" label is removed only when
 * every number was credited.
 *
 * @author vitex
 */
class VoipCredit extends \Ease\Sand
{
    public const PRODUCT_CODE = 'KREDIT_VOIP';
    public const PENDING_LABEL = 'API';
    public const ORDER_FILE = 'order.json';

    /**
     * Credit validity in days (IPEX_CREDIT_EXPIRATION overrides).
     */
    public const DEFAULT_EXPIRATION = 365;

    public function __construct(private ?\IPEXB2B\Voip $voip = null) {}

    public function voiper(): \IPEXB2B\Voip
    {
        if (null === $this->voip) {
            $this->voip = new \IPEXB2B\Voip();
        }

        return $this->voip;
    }

    /**
     * @return array{status: string, message: string, invoice: string, amount: float, credited: array<int, array<string, mixed>>, exitcode: int}
     */
    public function processInvoice(\AbraFlexi\FakturaVydana $invoice): array
    {
        $ident = (string) $invoice->getRecordIdent();
        $report = ['status' => 'skipped', 'message' => '', 'invoice' => $ident, 'amount' => 0.0, 'credited' => [], 'exitcode' => 0];

        $paid = $this->paidAmount($invoice);

        if ($paid <= 0.0) {
            $report['message'] = sprintf(_('%s: no %s item'), $ident, self::PRODUCT_CODE);

            return $report;
        }

        if (!\in_array(self::PENDING_LABEL, array_map([\AbraFlexi\Functions::class, 'uncode'], array_keys(\AbraFlexi\Stitek::listToArray($invoice->getDataValue('stitky')))), true)) {
            $report['message'] = sprintf(_('%s: already processed (no %s label)'), $ident, self::PENDING_LABEL);

            return $report;
        }

        $orders = $this->creditOrders($this->loadOrderData($invoice));

        if ([] === $orders) {
            return $this->fail($report, sprintf(_('%s: %s has no VoIP credit entry (ipexuser, phoneno)'), $ident, self::ORDER_FILE));
        }

        $requested = array_sum(array_column($orders, 'amount'));
        $report['amount'] = (float) $requested;

        // order.json is customer-supplied data: never credit more than was invoiced
        if ($requested > $paid + 0.01) {
            return $this->fail($report, sprintf(_('%s: %s requests %s but invoice has only %s'), $ident, self::ORDER_FILE, $requested, $paid));
        }

        foreach ($orders as $order) {
            if (!$this->credit($order, $invoice)) {
                $done = implode(', ', array_column($report['credited'], 'phoneno'));

                return $this->fail($report, sprintf(_('%s: crediting %s failed; already credited: [%s]; label kept - do not rerun blindly'), $ident, $order['phoneno'], $done));
            }

            $report['credited'][] = $order;
            $this->notify($order, $invoice);
        }

        if (!$invoice->unsetLabel(self::PENDING_LABEL)) {
            return $this->fail($report, sprintf(_('%s: credit added but label %s NOT removed; do not rerun!'), $ident, self::PENDING_LABEL));
        }

        $report['status'] = 'success';
        $report['message'] = sprintf(_('%s: credit %s added to %d number(s)'), $ident, $requested, \count($orders));

        return $report;
    }

    /**
     * Total amount of KREDIT_VOIP items on the invoice.
     */
    public function paidAmount(\AbraFlexi\FakturaVydana $invoice): float
    {
        $amount = 0.0;
        $items = $invoice->getDataValue('polozkyFaktury');

        foreach (\is_array($items) ? $items : [] as $item) {
            $kod = $item['kod'] ?? '';
            $kod = \is_array($kod) ? (string) ($kod['kod'] ?? reset($kod)) : (string) $kod;

            if (\AbraFlexi\Functions::uncode($kod) === self::PRODUCT_CODE) {
                $amount += (float) ($item['mnozMj'] ?? 1) * (float) ($item['cenaMj'] ?? 0);
            }
        }

        return $amount;
    }

    /**
     * Entries of order.json that describe a VoIP credit.
     *
     * @param array<int, array<string, mixed>> $orderData
     *
     * @return array<int, array{ipexuser: string, phoneno: string, amount: float, notify: string}>
     */
    public function creditOrders(array $orderData): array
    {
        $orders = [];

        foreach ($orderData as $entry) {
            if (!\is_array($entry) || empty($entry['phoneno']) || empty($entry['ipexuser'])) {
                continue;
            }

            $amount = (float) ($entry['mnozMj'] ?? 1) * (float) ($entry['cenaMj'] ?? 0);

            if ($amount > 0.0) {
                $orders[] = ['ipexuser' => (string) $entry['ipexuser'], 'phoneno' => (string) $entry['phoneno'], 'amount' => $amount, 'notify' => (string) ($entry['notify'] ?? '')];
            }
        }

        return $orders;
    }

    /**
     * Content of the order.json attachment (list of ordered items).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function loadOrderData(\AbraFlexi\FakturaVydana $invoice): array
    {
        foreach (\AbraFlexi\Priloha::getAttachmentsList($invoice) as $attachment) {
            if (($attachment['nazSoub'] ?? '') === self::ORDER_FILE) {
                $decoded = json_decode((string) \AbraFlexi\Priloha::getAttachment($attachment['id']), true);

                // tolerate a single merged object as well as the list
                return \is_array($decoded) ? (array_is_list($decoded) ? $decoded : [$decoded]) : [];
            }
        }

        return [];
    }

    /**
     * @param array{ipexuser: string, phoneno: string, amount: float, notify: string} $order
     */
    protected function credit(array $order, \AbraFlexi\FakturaVydana $invoice): bool
    {
        $voiper = $this->voiper();
        $voiper->setPostFields(json_encode([
            'customerId' => $order['ipexuser'],
            'amount' => $order['amount'],
            'expiration' => (int) \Ease\Shared::cfg('IPEX_CREDIT_EXPIRATION', self::DEFAULT_EXPIRATION),
        ]));
        $voiper->requestData($order['phoneno'].'/credit', 'PUT');
        $ok = 200 === $voiper->lastResponseCode;
        $this->addStatusMessage(
            sprintf(_('IPEX credit %s for %s by %s'), $order['amount'], $order['phoneno'], $invoice->getRecordIdent()),
            $ok ? 'success' : 'error',
        );

        return $ok;
    }

    /**
     * Notify customer; a mail failure never fails the job (credit is already added).
     *
     * @param array{ipexuser: string, phoneno: string, amount: float, notify: string} $order
     */
    protected function notify(array $order, \AbraFlexi\FakturaVydana $invoice): void
    {
        if ('' === $order['notify'] || !\Ease\Shared::cfg('NOTIFY_CUSTOMER', true)) {
            return;
        }

        try {
            $mail = new \Ease\Mailer(
                $order['notify'],
                _('VoIP credit was increased'),
                sprintf(
                    "%s\n"._('VoIP number %s credit was increased by %s.')."\n"._('Credit validity was prolonged by %d days.'),
                    (string) $invoice->getDataValue('firma@showAs'),
                    $order['phoneno'],
                    $order['amount'],
                    (int) \Ease\Shared::cfg('IPEX_CREDIT_EXPIRATION', self::DEFAULT_EXPIRATION),
                ),
            );
            $mail->send();
        } catch (\Throwable $exc) {
            $this->addStatusMessage(sprintf(_('Notification to %s failed: %s'), $order['notify'], $exc->getMessage()), 'warning');
        }
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function fail(array $report, string $message): array
    {
        $this->addStatusMessage($message, 'error');
        $report['status'] = 'error';
        $report['message'] = $message;
        $report['exitcode'] = 1;

        return $report;
    }
}
