<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->get('search', '');

        $customers = Customer::where('name', 'like', "%$search%")
            ->orWhere('phone', 'like', "%$search%")
            ->orWhere('address', 'like', "%$search%")
            ->orWhere('card_number', 'like', "%$search%")
            ->paginate(10);


        return response()->json([
            'status' => 'success',
            'data' => $customers,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCustomerRequest $request)
    {
        // create a new customer
        $customer = Customer::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'address' => $request->address,
            'card_number' => $request->card_number,
        ]);

        // return a response
        return response()->json([
            'status' => 'success',
            'data' => $customer,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer)
    {

        $customer = $customer->with(['transactions'])->find($customer->id);

        // return a response
        return response()->json([
            'status' => 'success',
            'data' => $customer,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Customer $customer)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Customer $customer)
    {
        // validate the request
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'address' => 'nullable|string|max:255',
            'card_number' => 'nullable|string|max:50',
        ]);

        // update the customer
        $customer->update($request->all());

        // return a response
        return response()->json([
            'status' => 'success',
            'data' => $customer,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Customer $customer)
    {
        // delete the customer transactions
        $transactions = Transaction::where('customer_id', $customer->id)->delete();

        // delete the customer
        $customer->delete();

        // return a response
        return response()->json([
            'status' => 'success',
            'message' => 'Customer deleted successfully.',
        ]);
    }
}
