<?php

namespace Anibalealvarezs\ShopifyApi\Enums;

enum FinancialStatus: string
{
    case authorized = 'authorized';
    case pending = 'pending';
    case paid = 'paid';
    case partially_paid = 'partially_paid';
    case refunded = 'refunded';
    case voided = 'voided';
    case partially_refunded = 'partially_refunded';
    case any = 'any';
    case unpaid = 'unpaid';
}
