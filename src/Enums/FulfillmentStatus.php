<?php

namespace Anibalealvarezs\ShopifyApi\Enums;

enum FulfillmentStatus: string
{
    case shipped = 'shipped'; // Returns orders with 'fulfillment_status' of 'fulfilled'
    case partial = 'partial'; // Returns partially shipped orders
    case unshipped = 'unshipped'; // Returns orders with 'fulfillment_status' of 'null'
    case any = 'any'; // Returns orders of any fulfillment status
    case unfulfilled = 'unfulfilled'; // Returns orders with 'fulfillment_status' of 'null' or 'partial'
}
