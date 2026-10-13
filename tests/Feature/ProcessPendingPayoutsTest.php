<?php

use App\Enums\PayoutStatus;
use App\Exceptions\MpesaApiException;
use App\Jobs\ProcessPayoutsJob;
use App\Models\Payee;
use App\Models\Payout;
use App\Services\MpesaService;
use Illuminate\Http\Client\ConnectionException;

function makePayout(PayoutStatus $status = PayoutStatus::Approved): Payout
{
    $payee = Payee::create([
        'name' => 'Test Payee',
        'phone' => '254700000000',
        'designation' => 'Staff',
        'team' => 'Ops',
    ]);

    return Payout::create([
        'payee_id' => $payee->id,
        'amount' => 1000,
        'reason' => 'Test payout',
        'status' => $status,
    ]);
}

it('does nothing when there are no approved payouts', function () {
    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->never();
    $this->app->instance(MpesaService::class, $mpesa);

    $this->artisan('payouts:process-pending')->assertSuccessful();
});

it('does not process payouts that are not approved', function () {
    makePayout(PayoutStatus::Processing);
    makePayout(PayoutStatus::Completed);

    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->never();
    $this->app->instance(MpesaService::class, $mpesa);

    $this->artisan('payouts:process-pending')->assertSuccessful();
});

it('marks the payout processing and stores the conversation id when B2C accepts the request', function () {
    $payout = makePayout();

    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->once()->andReturn([
        'ResponseCode' => '0',
        'ConversationID' => 'AG_1',
        'ResponseDescription' => 'Accept the service request successfully.',
    ]);
    $this->app->instance(MpesaService::class, $mpesa);

    $this->artisan('payouts:process-pending')->assertSuccessful();

    $payout->refresh();
    expect($payout->status)->toBe(PayoutStatus::Processing);
    expect($payout->conversation_id)->toBe('AG_1');
});

it('marks the payout failed when B2C synchronously rejects the request', function () {
    $payout = makePayout();

    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->once()->andReturn([
        'ResponseCode' => '1',
        'ResponseDescription' => 'Insufficient balance.',
    ]);
    $this->app->instance(MpesaService::class, $mpesa);

    $this->artisan('payouts:process-pending')->assertSuccessful();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Failed);
});

it('marks the payout failed when the B2C API call throws', function () {
    $payout = makePayout();

    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->once()->andThrow(new MpesaApiException('boom'));
    $this->app->instance(MpesaService::class, $mpesa);

    $this->artisan('payouts:process-pending')->assertFailed();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Failed);
});

it('marks the payout failed when the B2C connection fails', function () {
    $payout = makePayout();

    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->once()->andThrow(new ConnectionException('timed out'));
    $this->app->instance(MpesaService::class, $mpesa);

    $this->artisan('payouts:process-pending')->assertFailed();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Failed);
});

it('marks the payout failed instead of re-queueing it when the payouts job B2C call throws', function () {
    $payout = makePayout();

    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->once()->andThrow(new ConnectionException('timed out'));
    $this->app->instance(MpesaService::class, $mpesa);

    ProcessPayoutsJob::dispatchSync();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Failed);
});
