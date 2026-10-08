<?php

declare(strict_types=1);

/**
 * This file is part of the AbraFlexi VoIP Credit package
 *
 * (c) Vítězslav Dvořák <http://spojenet.cz/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet\AbraFlexiVoipCredit\Tests;

use PHPUnit\Framework\TestCase;
use SpojeNet\AbraFlexiVoipCredit\VoipCredit;

class VoipCreditTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private function invoice(array $data, bool $unsetOk = true, bool $expectUnset = true): \AbraFlexi\FakturaVydana
    {
        $invoice = $this->getMockBuilder(\AbraFlexi\FakturaVydana::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDataValue', 'getRecordIdent', 'unsetLabel', 'getEmail'])
            ->getMock();
        $invoice->method('getDataValue')->willReturnCallback(static fn ($k) => $data[$k] ?? null);
        $invoice->method('getRecordIdent')->willReturn('code:ZAL0002/2026');
        $invoice->method('getEmail')->willReturn('');
        $invoice->expects($expectUnset ? $this->once() : $this->never())->method('unsetLabel')->willReturn($unsetOk);

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function paid(string $kod = 'code:KREDIT_VOIP', string $labels = 'API', float $price = 300.0): array
    {
        return ['stitky' => $labels, 'stavUhrK' => 'stavUhr.uhrazeno', 'firma' => 'code:ACME', 'polozkyFaktury' => [['kod' => $kod, 'mnozMj' => '1', 'cenaMj' => (string) $price]]];
    }

    /**
     * @param array<int, array<string, mixed>> $orderData
     */
    private function credit(array $orderData, ?\IPEXB2B\Voip $voip, bool $owned = true): VoipCredit
    {
        $credit = $this->getMockBuilder(VoipCredit::class)->setConstructorArgs([$voip])->onlyMethods(['loadOrderData', 'numberBelongsToCustomer'])->getMock();
        $credit->method('loadOrderData')->willReturn($orderData);
        $credit->method('numberBelongsToCustomer')->willReturn($owned);

        return $credit;
    }

    private function voip(int $code, int $calls = 1): \IPEXB2B\Voip
    {
        $voip = $this->getMockBuilder(\IPEXB2B\Voip::class)->disableOriginalConstructor()->onlyMethods(['setPostFields', 'requestData'])->getMock();
        $voip->lastResponseCode = $code;
        $voip->expects($this->exactly($calls))->method('requestData')->with($this->stringEndsWith('/credit'), 'PUT');

        return $voip;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function order(string $phone = '420123456789', string $price = '300'): array
    {
        return [['ipexuser' => '77', 'phoneno' => $phone, 'cenaMj' => $price, 'notify' => 'attacker@example.com']];
    }

    public function testSuccessRemovesLabel(): void
    {
        $report = $this->credit($this->order(), $this->voip(200))->processInvoice($this->invoice($this->paid()));
        $this->assertSame('success', $report['status']);
        $this->assertSame(300.0, $report['amount']);
        $this->assertSame('420123456789', $report['credited'][0]['phoneno']);
    }

    public function testIpexFailureKeepsLabel(): void
    {
        $report = $this->credit($this->order(), $this->voip(500))->processInvoice($this->invoice($this->paid(), true, false));
        $this->assertSame('error', $report['status']);
        $this->assertSame(1, $report['exitcode']);
        $this->assertSame([], $report['credited']);
    }

    public function testOrderJsonCannotExceedInvoicedAmount(): void
    {
        $report = $this->credit($this->order('420123456789', '3000'), $this->voip(200, 0))->processInvoice($this->invoice($this->paid(), true, false));
        $this->assertSame('error', $report['status']);
    }

    public function testMissingOrderJsonFails(): void
    {
        $report = $this->credit([], $this->voip(200, 0))->processInvoice($this->invoice($this->paid(), true, false));
        $this->assertSame('error', $report['status']);
    }

    public function testOtherProductAndMissingLabelAreSkipped(): void
    {
        $r1 = $this->credit($this->order(), $this->voip(200, 0))->processInvoice($this->invoice($this->paid('code:KREDIT_DOMENA'), true, false));
        $r2 = $this->credit($this->order(), $this->voip(200, 0))->processInvoice($this->invoice($this->paid('code:KREDIT_VOIP', ''), true, false));
        $this->assertSame('skipped', $r1['status']);
        $this->assertSame('skipped', $r2['status']);
        $this->assertSame(0, $r1['exitcode'] + $r2['exitcode']);
    }

    public function testSecondNumberFailureReportsPartialAndKeepsLabel(): void
    {
        $orders = [...$this->order('420111', '100'), ...$this->order('420222', '100')];
        $voip = $this->getMockBuilder(\IPEXB2B\Voip::class)->disableOriginalConstructor()->onlyMethods(['setPostFields', 'requestData'])->getMock();
        $voip->lastResponseCode = 200;
        $calls = 0;
        $voip->method('requestData')->willReturnCallback(static function () use ($voip, &$calls): void {
            $voip->lastResponseCode = ++$calls > 1 ? 500 : 200;
        });
        $report = $this->credit($orders, $voip)->processInvoice($this->invoice($this->paid('code:KREDIT_VOIP', 'API', 200.0), true, false));
        $this->assertSame('error', $report['status']);
        $this->assertCount(1, $report['credited']);
        $this->assertStringContainsString('420111', $report['message']);
    }

    public function testMergedObjectAndNonVoipEntriesAreHandled(): void
    {
        $credit = new VoipCredit($this->createStub(\IPEXB2B\Voip::class));
        $this->assertSame([], $credit->creditOrders([['cenik' => 'code:X', 'cenaMj' => 5]]));
        $this->assertCount(1, $credit->creditOrders($this->order()));
    }

    public function testUrlLikePhoneNumberIsRejected(): void
    {
        $credit = new VoipCredit($this->createStub(\IPEXB2B\Voip::class));

        foreach (['http://evil.example/x', '/etc/x', '420123/../x', '420 123', '', "420123\n"] as $bad) {
            $this->assertSame([], $credit->creditOrders([['ipexuser' => '77', 'phoneno' => $bad, 'cenaMj' => 5]]), $bad);
        }

        $this->assertSame([], $credit->creditOrders([['ipexuser' => '77/../x', 'phoneno' => '420123456789', 'cenaMj' => 5]]));
        $normalized = $credit->creditOrders([['ipexuser' => '77', 'phoneno' => '+420123456789', 'cenaMj' => 5]]);
        $this->assertSame('420123456789', $normalized[0]['phoneno']);
    }

    public function testForeignNumberIsNotCredited(): void
    {
        $report = $this->credit($this->order(), $this->voip(200, 0), false)->processInvoice($this->invoice($this->paid(), true, false));
        $this->assertSame('error', $report['status']);
        $this->assertSame([], $report['credited']);
    }

    public function testUnpaidInvoiceIsSkipped(): void
    {
        $data = ['stavUhrK' => 'stavUhr.neuhrazeno', 'zbyvaUhradit' => '300'] + $this->paid();
        $report = $this->credit($this->order(), $this->voip(200, 0))->processInvoice($this->invoice($data, true, false));
        $this->assertSame('skipped', $report['status']);
    }

    public function testNumberMatchesRules(): void
    {
        $credit = new VoipCredit($this->createStub(\IPEXB2B\Voip::class));
        $order = $this->order()[0];
        $info = ['number' => '420123456789', 'paymentType' => 'prepaid', 'status' => 'active', 'customerId' => '77', 'customerExternId' => 'code:ACME'];
        $this->assertTrue($credit->numberMatches($info, $order, 'code:ACME'));
        $this->assertFalse($credit->numberMatches($info, $order, 'code:OTHER'));
        $this->assertFalse($credit->numberMatches(['customerId' => '99'] + $info, $order, 'code:ACME'));
        $this->assertFalse($credit->numberMatches(['paymentType' => 'postpaid'] + $info, $order, 'code:ACME'));
        $this->assertFalse($credit->numberMatches(['status' => 'suspended'] + $info, $order, 'code:ACME'));
        $this->assertFalse($credit->numberMatches(['customerExternId' => ''] + $info, $order, 'code:ACME'));
        $this->assertFalse($credit->numberMatches(['number' => '420999999999'] + $info, $order, 'code:ACME'));
        $this->assertFalse($credit->numberMatches(array_diff_key($info, ['number' => 1]), $order, 'code:ACME'));
        $this->assertTrue($credit->numberMatches(['number' => '+420123456789'] + $info, $order, 'code:ACME'));
        $this->assertFalse($credit->numberMatches([], $order, 'code:ACME'));
    }
}
