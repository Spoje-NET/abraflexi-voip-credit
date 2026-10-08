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
 * (ipexuser, phoneno, cenaMj). The "API" label is removed only when
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

    /**
     * order.json is customer-supplied: values go to an URL path, so accept digits only.
     */
    public const PHONE_PATTERN = '/^\+?[0-9]{6,15}$/D';
    public const IPEX_USER_PATTERN = '/^[0-9A-Za-z_-]{1,40}$/D';

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

        if (!$this->isPaid($invoice)) {
            $report['message'] = sprintf(_('%s is not paid'), $ident);

            return $report;
        }

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

        // verify ALL numbers first so nothing is credited when one of them is foreign
        foreach ($orders as $order) {
            if (!$this->numberBelongsToCustomer($order, $invoice)) {
                return $this->fail($report, sprintf(_('%s: number %s is not an active prepaid number of the invoiced customer'), $ident, $order['phoneno']));
            }
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
     * Fully paid invoice? Exact state match (note "stavUhr.neuhrazeno" contains "uhrazeno").
     */
    public function isPaid(\AbraFlexi\FakturaVydana $invoice): bool
    {
        $state = (string) $invoice->getDataValue('stavUhrK');
        $remaining = (string) $invoice->getDataValue('zbyvaUhradit');

        if ('' !== $remaining && (float) $remaining > 0.0) {
            return false;
        }

        return \in_array($state, ['stavUhr.uhrazeno', 'stavUhr.uhrazenoRucne'], true) || ('' !== $remaining && 0.0 === (float) $remaining);
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
     * @return array<int, array{ipexuser: string, phoneno: string, amount: float}>
     */
    public function creditOrders(array $orderData): array
    {
        $orders = [];

        foreach ($orderData as $entry) {
            if (!\is_array($entry) || !preg_match(self::PHONE_PATTERN, (string) ($entry['phoneno'] ?? '')) || !preg_match(self::IPEX_USER_PATTERN, (string) ($entry['ipexuser'] ?? ''))) {
                continue;
            }

            $amount = (float) ($entry['mnozMj'] ?? 1) * (float) ($entry['cenaMj'] ?? 0);

            if ($amount > 0.0) {
                $orders[] = ['ipexuser' => (string) $entry['ipexuser'], 'phoneno' => (string) $entry['phoneno'], 'amount' => $amount];
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
     * The number must be an active prepaid IPEX number of the customer on the invoice
     * and the IPEX customer id must match (never trust order.json alone).
     *
     * @param array{ipexuser: string, phoneno: string, amount: float} $order
     */
    protected function numberBelongsToCustomer(array $order, \AbraFlexi\FakturaVydana $invoice): bool
    {
        $servicer = new \IPEXB2B\Services();
        $servicer->ignore404(true);
        $servicer->loadFromIPEX(['number' => $order['phoneno']]);
        $servicer->ignore404(false);
        $info = $servicer->getData()[0] ?? [];

        return $this->numberMatches($info, $order, (string) $invoice->getDataValue('firma'));
    }

    /**
     * @param array<string, mixed>                                    $info IPEX number details
     * @param array{ipexuser: string, phoneno: string, amount: float} $order
     */
    public function numberMatches(array $info, array $order, string $firma): bool
    {
        return [] !== $info
            && 'prepaid' === ($info['paymentType'] ?? null)
            && 'active' === ($info['status'] ?? null)
            && (string) ($info['customerId'] ?? '') === $order['ipexuser']
            && '' !== (string) ($info['customerExternId'] ?? '')
            && \AbraFlexi\Functions::uncode((string) $info['customerExternId']) === \AbraFlexi\Functions::uncode($firma);
    }

    /**
     * @param array{ipexuser: string, phoneno: string, amount: float} $order
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
     * @param array{ipexuser: string, phoneno: string, amount: float} $order
     */
    protected function notify(array $order, \AbraFlexi\FakturaVydana $invoice): void
    {
        if (!filter_var(\Ease\Shared::cfg('NOTIFY_CUSTOMER', true), \FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        try {
            // recipient comes from AbraFlexi, never from customer-supplied order.json
            $recipient = (string) $invoice->getEmail();

            if (!filter_var($recipient, \FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $mail = new \Ease\Mailer(
                $recipient,
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
            $this->addStatusMessage(sprintf(_('Notification for %s failed: %s'), $order['phoneno'], $exc->getMessage()), 'warning');
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
