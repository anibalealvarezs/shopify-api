<?php

namespace Anibalealvarezs\ShopifyApi\Enums;

enum Status: string
{
    case open = 'open';
    case closed = 'closed';
    case cancelled = 'cancelled';
    case any = 'any';
}
