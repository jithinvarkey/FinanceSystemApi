<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BlockCustomerRequest;
use App\Http\Requests\StoreCustomerBankAccountRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Resources\CustomerBankAccountResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P4.1–P4.5 — Customer master endpoints. Approval itself happens through the
 * generic approvals inbox (P0.4); submitting a customer raises that request.
 */
final class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly CustomerService $service,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-receivable.view');

        return CustomerResource::collection(
            $this->customers->paginate(
                filters: array_merge(
                    $request->only(['status', 'customer_type', 'is_blocked']),
                    ['search' => $request->string('search')->toString() ?: null],
                ),
                perPage: min((int) $request->integer('per_page', 25), 100),
            ),
        );
    }

    /** P4.1 — customers whose commercial registration expires soon (compliance alert). */
    public function expiring(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-receivable.view');

        $days = min((int) $request->integer('days', 30), 365);

        return CustomerResource::collection(
            Customer::query()->registrationExpiringWithin($days)->orderBy('commercial_reg_expiry')->get(),
        );
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        $this->authorize('accounts-receivable.view');

        return new CustomerResource($customer->load('bankAccounts'));
    }

    public function update(StoreCustomerRequest $request, Customer $customer): CustomerResource
    {
        return new CustomerResource($this->service->updateDraft($customer, $request->validated()));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('accounts-receivable.manage');
        $this->service->deleteDraft($customer);

        return response()->json(null, 204);
    }

    public function submit(Request $request, Customer $customer): CustomerResource
    {
        $this->authorize('accounts-receivable.manage');

        return new CustomerResource($this->service->submit($customer, (int) $request->user()->id));
    }

    public function block(BlockCustomerRequest $request, Customer $customer): CustomerResource
    {
        return new CustomerResource(
            $this->service->block($customer, (int) $request->user()->id, $request->string('reason')->toString()),
        );
    }

    public function unblock(Customer $customer): CustomerResource
    {
        $this->authorize('accounts-receivable.manage');

        return new CustomerResource($this->service->unblock($customer));
    }

    // ----- Bank accounts (P4.4) -----

    public function addBankAccount(StoreCustomerBankAccountRequest $request, Customer $customer): JsonResponse
    {
        $account = $this->service->addBankAccount($customer, $request->validated());

        return (new CustomerBankAccountResource($account))->response()->setStatusCode(201);
    }

    public function updateBankAccount(StoreCustomerBankAccountRequest $request, CustomerBankAccount $bankAccount): CustomerBankAccountResource
    {
        return new CustomerBankAccountResource($this->service->updateBankAccount($bankAccount, $request->validated()));
    }

    public function verifyBankAccount(CustomerBankAccount $bankAccount): CustomerBankAccountResource
    {
        $this->authorize('accounts-receivable.approve');

        return new CustomerBankAccountResource($this->service->verifyBankAccount($bankAccount));
    }

    public function deleteBankAccount(CustomerBankAccount $bankAccount): JsonResponse
    {
        $this->authorize('accounts-receivable.manage');
        $bankAccount->delete();

        return response()->json(null, 204);
    }
}
