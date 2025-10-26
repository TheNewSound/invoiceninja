<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\PaymentDrivers\Mollie;

use App\Exceptions\PaymentFailed;
use App\Http\Requests\ClientPortal\Payments\PaymentResponseRequest;
use App\Jobs\Util\SystemLogger;
use App\Models\GatewayType;
use App\Models\Payment;
use App\Models\PaymentType;
use App\Models\SystemLog;
use App\PaymentDrivers\Common\LivewireMethodInterface;
use App\PaymentDrivers\Common\MethodInterface;
use App\PaymentDrivers\MolliePaymentDriver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IDEAL implements MethodInterface, LivewireMethodInterface
{
    protected MolliePaymentDriver $mollie;

    public function __construct(MolliePaymentDriver $mollie)
    {
        $this->mollie = $mollie;

        $this->mollie->init();
    }

    /**
     * Show the authorization page for iDEAL.
     *
     * @param array $data
     * @return \Illuminate\View\View
     */
    public function authorizeView(array $data): View
    {
        return render('gateways.mollie.ideal.authorize', $data);
    }

    /**
     * Handle the authorization for iDEAL.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function authorizeResponse(Request $request): RedirectResponse
    {
        return redirect()->route('client.payment_methods.index');
    }

    /**
     * Show the payment page for iDEAL.
     *
     * @param array $data
     * @return \Illuminate\Http\RedirectResponseor|RedirectResponse
     */
    public function paymentView(array $data)
    {
        $this->mollie->payment_hash
            ->withData('gateway_type_id', GatewayType::IDEAL)
            ->withData('client_id', $this->mollie->client->id);

        try {
            $data = [
                'method' => 'ideal',
                'amount' => [
                    'currency' => $this->mollie->client->currency()->code,
                    'value' => $this->mollie->convertToMollieAmount((float) $this->mollie->payment_hash->data->amount_with_fee),
                ],
                'description' => \sprintf('%s: %s', ctrans('texts.invoices'), \implode(', ', collect($data['invoices'])->pluck('invoice_number')->toArray())),
                'redirectUrl' => route('client.payments.response', [
                    'company_gateway_id' => $this->mollie->company_gateway->id,
                    'payment_hash' => $this->mollie->payment_hash->hash,
                    'payment_method_id' => GatewayType::IDEAL,
                ]),
                'webhookUrl' => $this->mollie->company_gateway->webhookUrl(),
                'metadata' => [
                    'client_id' => $this->mollie->client->hashed_id,
                    'hash' => $this->mollie->payment_hash->hash,
                    'gateway_type_id' => GatewayType::IDEAL,
                    'payment_type_id' => PaymentType::IDEAL,
                ],
            ];

            if ($this->mollie->company_gateway->token_billing == 'always') {
                // Check if a mollie CustomerId already exists for this client, if so, use that
                $gateway_customer_reference = null;
                if ($this->mollie->client->gateway_tokens->count() > 0) {
                    $gateway_customer_reference = $this->mollie->client->gateway_tokens->first()->gateway_customer_reference;
                } else {
                    $customer = $this->mollie->gateway->customers->create([
                        'name' => $this->mollie->client->name,
                        'email' => $this->mollie->client->present()->email(),
                        'metadata' => [
                            'id' => $this->mollie->client->hashed_id,
                        ],
                    ]);
                    $gateway_customer_reference = $customer->id;
                }

                $data['customerId'] = $gateway_customer_reference;
                $data['sequenceType'] = 'first';

                $this->mollie->payment_hash
                    ->withData('mollieCustomerId', $gateway_customer_reference)
                    ->withData('shouldStoreToken', true);
            }

            $payment = $this->mollie->gateway->payments->create($data);

            $this->mollie->payment_hash->withData('payment_id', $payment->id);

            return redirect(
                $payment->getCheckoutUrl()
            );
        } catch (\Mollie\Api\Exceptions\ApiException | \Exception $exception) {
            return $this->processUnsuccessfulPayment($exception);
        }
    }

    /**
     * Handle unsuccessful payment.
     *
     * @param Exception $exception
     * @throws PaymentFailed
     * @return void
     */
    public function processUnsuccessfulPayment(\Exception $exception): void
    {
        $this->mollie->sendFailureMail($exception->getMessage());

        SystemLogger::dispatch(
            $exception->getMessage(),
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_FAILURE,
            SystemLog::TYPE_MOLLIE,
            $this->mollie->client,
            $this->mollie->client->company,
        );

        throw new PaymentFailed($exception->getMessage(), $exception->getCode());
    }

    /**
     * Handle the payments for the iDEAL.
     *
     * @param PaymentResponseRequest $request
     * @return mixed
     */
    public function paymentResponse(PaymentResponseRequest $request)
    {
        if (! \property_exists($this->mollie->payment_hash->data, 'payment_id')) {
            return $this->processUnsuccessfulPayment(
                new PaymentFailed('Whoops, something went wrong. Missing required [payment_id] parameter. Please contact administrator. Reference hash: '.$this->mollie->payment_hash->hash)
            );
        }

        try {
            $payment = $this->mollie->gateway->payments->get(
                $this->mollie->payment_hash->data->payment_id
            );

            if ($payment->status === 'paid') {
                return $this->processSuccessfulPayment($payment);
            }

            if ($payment->status === 'open') {
                return $this->processOpenPayment($payment);
            }

            if ($payment->status === 'failed') {
                return $this->processUnsuccessfulPayment(
                    new PaymentFailed(ctrans('texts.status_failed'))
                );
            }

            return $this->processUnsuccessfulPayment(
                new PaymentFailed(ctrans('texts.status_voided'))
            );
        } catch (\Mollie\Api\Exceptions\ApiException | \Exception $exception) {
            return $this->processUnsuccessfulPayment($exception);
        }
    }

    /**
     * Handle the successful payment for iDEAL.
     *
     * @param string $status
     * @param ResourcesPayment $payment
     * @return \Illuminate\Http\RedirectResponse
     */
    public function processSuccessfulPayment(\Mollie\Api\Resources\Payment $payment, string $status = 'paid'): RedirectResponse
    {
        $payment_hash = $this->mollie->payment_hash;

        if (property_exists($payment_hash->data, 'shouldStoreToken') && $payment_hash->data->shouldStoreToken) {
            $mandates = \iterator_to_array($this->mollie->gateway->mandates->listForId($payment_hash->data->mollieCustomerId));

            $payment_method_id = MolliePaymentDriver::convertToGatewayType($mandates[0]->details->method);

            $payment_meta = new \stdClass();
            $payment_meta->type = $payment_method_id;
            if ($payment_method_id == GatewayType::CREDIT_CARD) {
                $payment_meta->exp_month = (string) $mandates[0]->details->cardExpiryDate;
                $payment_meta->exp_year = (string) '';
                $payment_meta->brand = (string) $mandates[0]->details->cardLabel;
                $payment_meta->last4 = (string) $mandates[0]->details->cardNumber;
            } elseif ($payment_method_id == GatewayType::DIRECT_DEBIT) {
                $payment_meta->consumerAccount = $mandates[0]->details->consumerAccount;
                $payment_meta->consumerBic = $mandates[0]->details->consumerBic;
            }

            $this->mollie->storeGatewayToken([
                'token' => $mandates[0]->id,
                'payment_method_id' => $payment_method_id,
                'payment_meta' =>  $payment_meta,
            ], ['gateway_customer_reference' => $payment_hash->data->mollieCustomerId]);
        }

        $p = \App\Models\Payment::query()
                    ->withTrashed()
                    ->where('company_id', $this->mollie->client->company_id)
                    ->where('transaction_reference', $payment->id)
                    ->first();

        if ($p) {
            $p->status_id = Payment::STATUS_COMPLETED;
            $p->save();

            return redirect()->route('client.payments.show', ['payment' => $p->hashed_id]);
        }

        $data = [
            'gateway_type_id' => GatewayType::IDEAL,
            'amount' => array_sum(array_column($this->mollie->payment_hash->invoices(), 'amount')) + $this->mollie->payment_hash->fee_total,
            'payment_type' => PaymentType::IDEAL,
            'transaction_reference' => $payment->id,
            'idempotency_key' => substr("{$payment->id}{$this->mollie->payment_hash->hash}", 0, 64)
        ];

        $payment_record = $this->mollie->createPayment(
            $data,
            $status === 'paid' ? Payment::STATUS_COMPLETED : Payment::STATUS_PENDING
        );

        SystemLogger::dispatch(
            ['response' => $payment, 'data' => $data],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_SUCCESS,
            SystemLog::TYPE_MOLLIE,
            $this->mollie->client,
            $this->mollie->client->company,
        );

        return redirect()->route('client.payments.show', ['payment' => $payment_record->hashed_id]);
    }

    /**
     * Handle 'open' payment status for IDEAL.
     *
     * @param ResourcesPayment $payment
     * @return \Illuminate\Http\RedirectResponse
     */
    public function processOpenPayment(\Mollie\Api\Resources\Payment $payment): RedirectResponse
    {
        return $this->processSuccessfulPayment($payment, 'open');
    }

    /**
     * @inheritDoc
     */
    public function livewirePaymentView(array $data): string
    {
        // Doesn't support, it's offsite payment method.

        return '';
    }

    /**
     * @inheritDoc
     */
    public function paymentData(array $data): array
    {
        $this->paymentView($data);

        return $data;
    }
}
