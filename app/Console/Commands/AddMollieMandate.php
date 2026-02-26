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

namespace App\Console\Commands;

/**
 * Command to add or create Mollie direct debit mandates for clients.
 *
 * Usage Examples:
 *
 * 1. Add an existing mandate:
 *    php artisan ninja:add-mollie-mandate 123 "mdt_abc123def456"
 *
 * 2. Create a new direct debit mandate:
 *    php artisan ninja:add-mollie-mandate 123 create \
 *      --consumer-account="NL55INGB0000000000" \
 *      --consumer-bic="INGBNL2A" \
 *      --mandate-reference="REF-001"
 */

use App\Models\Client;
use App\Models\ClientGatewayToken;
use App\Models\CompanyGateway;
use App\Models\GatewayType;
use App\PaymentDrivers\MolliePaymentDriver;
use App\Utils\Traits\MakesHash;
use Illuminate\Console\Command;
use Mollie\Api\Exceptions\ApiException;

class AddMollieMandate extends Command
{
    use MakesHash;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ninja:add-mollie-mandate
                            {client_id : The client ID (hashed or unhashed)}
                            {mandate_id : The Mollie mandate ID (use "create" to create new mandate)}
                            {--consumer-account= : Consumer IBAN (required for new direct debit mandates)}
                            {--consumer-bic= : Consumer bank BIC (optional for new direct debit mandates)}
                            {--mandate-reference= : Mandate reference (optional for new direct debit mandates)}
                            {--company-gateway-id= : The company gateway ID for Mollie (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually add or create a Mollie direct debit mandate for a client using the Mollie SDK';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $clientId = $this->argument('client_id');
        $mandateId = $this->argument('mandate_id');

        try {
            // Find the client
            $client = $this->findClient($clientId);

            if (!$client) {
                $this->error("Client with ID '{$clientId}' not found.");
                return 1;
            }

            $this->info("Found client: {$client->name}");

            // Find the Mollie company gateway
            $companyGateway = $this->findMollieCompanyGateway($client);

            if (!$companyGateway) {
                $this->error("No Mollie company gateway found for client's company.");
                return 1;
            }

            $this->info("Using Mollie gateway: {$companyGateway->label}");

            // Initialize Mollie driver
            $mollieDriver = new MolliePaymentDriver($companyGateway, $client);
            $mollieDriver->init();

            // Check if we need to create a new mandate
            if ($mandateId === 'create') {
                return $this->createNewMandate($client, $companyGateway, $mollieDriver);
            }

            // Handle existing mandate
            return $this->addExistingMandate($client, $companyGateway, $mollieDriver, $mandateId);

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Find client by hashed or unhashed ID
     *
     * @param string $clientId
     * @return Client|null
     */
    private function findClient(string $clientId): ?Client
    {
        // Try as hashed ID first (contains letters)
        if (preg_match('/[a-zA-Z]/', $clientId)) {
            $unhashedId = $this->decodePrimaryKey($clientId);
            if ($unhashedId) {
                return Client::find($unhashedId);
            }
        }

        // Try as regular ID
        return Client::find($clientId);
    }

    /**
     * Find Mollie company gateway for the client's company
     *
     * @param Client $client
     * @return CompanyGateway|null
     */
    private function findMollieCompanyGateway(Client $client): ?CompanyGateway
    {
        $query = CompanyGateway::where('company_id', $client->company_id)
            ->where('gateway_key', '1bd651fb213ca0c9d66ae3c336dc77e8'); // Mollie gateway key

        // If company gateway ID is provided, use it
        if ($this->option('company-gateway-id')) {
            $gatewayId = $this->option('company-gateway-id');

            // Try as hashed ID first (contains letters)
            if (preg_match('/[a-zA-Z]/', $gatewayId)) {
                $unhashedId = $this->decodePrimaryKey($gatewayId);
                if ($unhashedId) {
                    $query->where('id', $unhashedId);
                }
            } else {
                $query->where('id', $gatewayId);
            }
        }

        return $query->first();
    }

    /**
     * Create ClientGatewayToken record for the mandate
     *
     * @param Client $client
     * @param CompanyGateway $companyGateway
     * @param string $mandateId
     * @param string $customerId
     * @param \Mollie\Api\Resources\Mandate $mandate
     * @return ClientGatewayToken
     */
    private function createClientGatewayToken(
        Client $client,
        CompanyGateway $companyGateway,
        string $mandateId,
        string $customerId,
        \Mollie\Api\Resources\Mandate $mandate
    ): ClientGatewayToken {
        // Determine gateway type from mandate method
        $gatewayTypeId = $this->getGatewayTypeId($mandate->method);

        // Create payment meta object
        $paymentMeta = new \stdClass();
        $paymentMeta->type = $gatewayTypeId;

        // Add method-specific details
        if ($mandate->method === 'creditcard' && isset($mandate->details)) {
            $paymentMeta->brand = $mandate->details->cardLabel ?? 'Unknown';
            $paymentMeta->last4 = $mandate->details->cardNumber ?? '****';

            if (isset($mandate->details->cardExpiryDate)) {
                $dateParts = explode('-', $mandate->details->cardExpiryDate);
                if (count($dateParts) >= 2) {
                    $paymentMeta->exp_year = substr($dateParts[0], -2);
                    $paymentMeta->exp_month = ltrim($dateParts[1], '0');
                }
            }
        } elseif ($mandate->method === 'directdebit' && isset($mandate->details)) {
            $paymentMeta->last4 = substr($mandate->details->consumerAccount ?? '', -4);
            $paymentMeta->brand = "mollie";
        }

        // Create new ClientGatewayToken
        $token = new ClientGatewayToken();
        $token->client_id = $client->id;
        $token->company_id = $client->company_id;
        $token->company_gateway_id = $companyGateway->id;
        $token->gateway_type_id = $gatewayTypeId;
        $token->token = $mandateId;
        $token->gateway_customer_reference = $customerId;
        $token->meta = $paymentMeta;
        $token->is_default = true;
        $token->save();

        return $token;
    }

    /**
     * Convert Mollie method to GatewayType ID
     *
     * @param string $method
     * @return int
     */
    private function getGatewayTypeId(string $method): int
    {
        $methodMap = [
            'creditcard' => GatewayType::CREDIT_CARD,
            'directdebit' => GatewayType::DIRECT_DEBIT,
            'bancontact' => GatewayType::BANCONTACT,
            'banktransfer' => GatewayType::BANK_TRANSFER,
            'ideal' => GatewayType::IDEAL,
            'kbc' => GatewayType::KBC,
        ];

        return $methodMap[strtolower($method)] ?? GatewayType::BANK_TRANSFER;
    }

    /**
     * Create a new direct debit mandate in Mollie
     *
     * @param Client $client
     * @param CompanyGateway $companyGateway
     * @param MolliePaymentDriver $mollieDriver
     * @return int
     */
    private function createNewMandate(
        Client $client,
        CompanyGateway $companyGateway,
        MolliePaymentDriver $mollieDriver
    ): int {
        $consumerAccount = $this->option('consumer-account');
        $consumerBic = $this->option('consumer-bic');
        $mandateReference = $this->option('mandate-reference');

        // Validate required parameters
        if (!$consumerAccount) {
            $this->error('--consumer-account is required when creating a new mandate');
            return 1;
        }

        // Find or create Mollie customer
        $customerId = $this->findOrCreateMollieCustomer($client, $mollieDriver);

        // Prepare mandate data using client's display name
        $clientName = $client->present()->name();
        $mandateData = [
            'method' => 'directdebit',
            'consumerName' => $clientName,
            'consumerAccount' => $consumerAccount,
        ];

        if ($consumerBic) {
            $mandateData['consumerBic'] = $consumerBic;
        }

        if ($mandateReference) {
            $mandateData['mandateReference'] = $mandateReference;
        }

        $this->info("Creating direct debit mandate for customer: {$customerId}");
        $this->info("Consumer Name: {$clientName}");
        $this->info("Consumer Account: {$consumerAccount}");
        if ($consumerBic) {
            $this->info("Consumer BIC: {$consumerBic}");
        }
        if ($mandateReference) {
            $this->info("Mandate Reference: {$mandateReference}");
        }

        try {
            // Create the mandate
            $mandate = $mollieDriver->gateway->mandates->createForId($customerId, $mandateData);

            $this->info("Successfully created mandate: {$mandate->id}");
            $this->info("Status: {$mandate->status}");

            // Create ClientGatewayToken record
            $this->createClientGatewayToken($client, $companyGateway, $mandate->id, $customerId, $mandate);

            $this->info("Successfully added direct debit mandate '{$mandate->id}' for client '{$client->name}'");

            return 0;

        } catch (ApiException $e) {
            $this->error("Failed to create mandate: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Add an existing mandate from Mollie
     *
     * @param Client $client
     * @param CompanyGateway $companyGateway
     * @param MolliePaymentDriver $mollieDriver
     * @param string $mandateId
     * @return int
     */
    private function addExistingMandate(
        Client $client,
        CompanyGateway $companyGateway,
        MolliePaymentDriver $mollieDriver,
        string $mandateId
    ): int {
        // Check if mandate already exists
        $existingToken = ClientGatewayToken::where('client_id', $client->id)
            ->where('company_gateway_id', $companyGateway->id)
            ->where('token', $mandateId)
            ->first();

        if ($existingToken) {
            $this->error("Mandate '{$mandateId}' already exists for this client.");
            return 1;
        }

        // Get mandate details from Mollie to extract customer ID
        try {
            $mandate = $mollieDriver->gateway->mandates->get($mandateId);
            $customerId = $mandate->customerId;

            $this->info("Mandate found for customer: {$customerId}");
            $this->info("Payment method: {$mandate->method}");
            $this->info("Status: {$mandate->status}");

        } catch (ApiException $e) {
            $this->error("Failed to retrieve mandate from Mollie: " . $e->getMessage());
            return 1;
        }

        // Ensure customer exists in Mollie
        try {
            $customer = $mollieDriver->gateway->customers->get($customerId);
            $this->info("Customer found: {$customer->name} ({$customer->email})");
        } catch (ApiException $e) {
            $this->error("Customer '{$customerId}' not found in Mollie: " . $e->getMessage());
            return 1;
        }

        // Create or update ClientGatewayToken
        $this->createClientGatewayToken($client, $companyGateway, $mandateId, $customerId, $mandate);

        $this->info("Successfully added Mollie mandate '{$mandateId}' for client '{$client->name}'");

        return 0;
    }

    /**
     * Find or create a Mollie customer for the client
     *
     * @param Client $client
     * @param MolliePaymentDriver $mollieDriver
     * @return string
     */
    private function findOrCreateMollieCustomer(Client $client, MolliePaymentDriver $mollieDriver): string
    {
        // Check if client already has a Mollie customer reference
        $existingToken = $client->gateway_tokens()
            ->where('company_gateway_id', $mollieDriver->company_gateway->id)
            ->whereNotNull('gateway_customer_reference')
            ->first();

        if ($existingToken) {
            $customerId = $existingToken->gateway_customer_reference;

            // Verify customer still exists in Mollie
            try {
                $customer = $mollieDriver->gateway->customers->get($customerId);
                $this->info("Using existing Mollie customer: {$customer->name} ({$customerId})");
                return $customerId;
            } catch (ApiException $e) {
                $this->info("Existing customer not found in Mollie, creating new one...");
            }
        }

        // Create new customer
        try {
            $customerData = [
                'name' => $client->name,
                'email' => $client->present()->email(),
                'metadata' => [
                    'id' => $client->hashed_id
                ],
            ];

            $customer = $mollieDriver->gateway->customers->create($customerData);
            $this->info("Created new Mollie customer: {$customer->name} ({$customer->id})");

            return $customer->id;

        } catch (ApiException $e) {
            throw new \Exception("Failed to create Mollie customer: " . $e->getMessage());
        }
    }
}
