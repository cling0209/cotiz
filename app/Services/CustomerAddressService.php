<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Order;
use App\Models\User;

class CustomerAddressService
{
    public function defaultForUser(User $user): ?Address
    {
        return $user->addresses()
            ->where('is_default', true)
            ->first()
            ?? $user->addresses()->latest()->first();
    }

    /**
     * @return array<string, string|null>
     */
    public function checkoutDefaults(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $address = $this->defaultForUser($user);

        if (! $address) {
            $order = Order::query()
                ->where('user_id', $user->id)
                ->latest()
                ->first();

            if ($order) {
                return [
                    'customer_name' => $order->customer_name,
                    'email' => $order->customer_email,
                    'recipient_name' => $order->shipping_recipient_name,
                    'phone' => $order->shipping_phone,
                    'region' => $order->shipping_region,
                    'comuna' => $order->shipping_comuna,
                    'street' => $order->shipping_street,
                    'street_number' => $order->shipping_street_number,
                    'apartment' => $order->shipping_apartment,
                ];
            }

            return [
                'customer_name' => $user->name,
                'email' => $user->email,
            ];
        }

        return [
            'customer_name' => $user->name,
            'email' => $user->email,
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'region' => $address->region,
            'comuna' => $address->comuna,
            'street' => $address->street,
            'street_number' => $address->street_number,
            'apartment' => $address->apartment,
        ];
    }

    public function syncDefaultFromShipping(User $user, array $shipping): Address
    {
        $payload = [
            'label' => 'Principal',
            'recipient_name' => $shipping['recipient_name'],
            'phone' => $shipping['phone'],
            'region' => $shipping['region'],
            'comuna' => $shipping['comuna'],
            'street' => $shipping['street'],
            'street_number' => $shipping['street_number'] ?? null,
            'apartment' => $shipping['apartment'] ?? null,
            'is_default' => true,
        ];

        $address = $this->defaultForUser($user);

        if ($address) {
            $address->update($payload);
        } else {
            $address = $user->addresses()->create($payload);
        }

        $user->addresses()
            ->where('id', '!=', $address->id)
            ->update(['is_default' => false]);

        return $address->fresh();
    }
}
