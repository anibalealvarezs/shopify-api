<?php

namespace Anibalealvarezs\ShopifyApi\Enums;

enum PublishedStatus: string
{
    case active = 'active';
    case archived = 'archived';
    case draft = 'draft';
}
